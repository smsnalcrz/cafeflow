#!/usr/bin/env python3
"""
CafeFlow POS — Automated Cloudflare Temporary Tunnel Deployment Tool
====================================================================
Provides both a desktop GUI (default) and CLI control (`on`, `off`, `status`)
to launch and stop a temporary Cloudflare Quick Tunnel (`*.trycloudflare.com`)
pointing to local XAMPP Apache (`http://localhost:80`), with one-click copy
links for CafeFlow POS (`/cafeflow/` and `/cafeflow/login.php`).

Usage:
  GUI Mode (default):
    python cloudflare_deploy/cloudflare_deploy.py

  CLI Mode:
    python cloudflare_deploy/cloudflare_deploy.py on
    python cloudflare_deploy/cloudflare_deploy.py off
    python cloudflare_deploy/cloudflare_deploy.py status
"""

from __future__ import annotations

import argparse
import json
import os
import platform
import queue
import re
import shutil
import signal
import subprocess
import sys
import threading
import time
import urllib.request
import webbrowser
from pathlib import Path
from typing import Optional

# ---------------------------------------------------------------------------
# Paths & Constants
# ---------------------------------------------------------------------------
TOOLS_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = TOOLS_DIR.parent
BIN_DIR = TOOLS_DIR / "bin"
STATE_FILE = TOOLS_DIR / ".cf_tunnel_state.json"
LOG_FILE = TOOLS_DIR / ".cf_tunnel.log"

CLOUDFLARED_WIN_URL = (
    "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe"
)
DEFAULT_LOCAL_ORIGIN = "http://localhost:80"
CAFEFLOW_SUBPATH = "/cafeflow"
TRYCLOUDFLARE_REGEX = re.compile(r"https://[a-zA-Z0-9\-]+\.trycloudflare\.com")


# ---------------------------------------------------------------------------
# Helper: Locate or Auto-Download cloudflared
# ---------------------------------------------------------------------------
def find_cloudflared() -> Optional[Path]:
    """Return path to cloudflared executable if installed in PATH or tools/bin."""
    exe_name = "cloudflared.exe" if os.name == "nt" else "cloudflared"
    local_bin = BIN_DIR / exe_name
    if local_bin.is_file():
        return local_bin

    direct_tool = TOOLS_DIR / exe_name
    if direct_tool.is_file():
        return direct_tool

    which_path = shutil.which("cloudflared")
    if which_path:
        return Path(which_path)

    # Check common Windows winget / Program Files paths
    if os.name == "nt":
        candidates = [
            Path(os.environ.get("ProgramFiles", "C:\\Program Files")) / "cloudflared" / "cloudflared.exe",
            Path(os.environ.get("ProgramFiles(x86)", "C:\\Program Files (x86)")) / "cloudflared" / "cloudflared.exe",
            Path(os.environ.get("LOCALAPPDATA", "")) / "Microsoft" / "WinGet" / "Links" / "cloudflared.exe",
        ]
        for c in candidates:
            if c.is_file():
                return c
    return None


def download_cloudflared(progress_cb=None) -> Path:
    """Download the official cloudflared binary into tools/bin/cloudflared.exe."""
    BIN_DIR.mkdir(parents=True, exist_ok=True)
    exe_name = "cloudflared.exe" if os.name == "nt" else "cloudflared"
    dest = BIN_DIR / exe_name

    if progress_cb:
        progress_cb("Downloading official cloudflared binary from GitHub...")

    req = urllib.request.Request(
        CLOUDFLARED_WIN_URL,
        headers={"User-Agent": "CafeFlow-Cloudflare-Deployer/1.0"},
    )
    with urllib.request.urlopen(req, timeout=60) as resp, open(dest, "wb") as out_f:
        total = int(resp.info().get("Content-Length", 0) or 0)
        downloaded = 0
        last_pct = -10
        chunk_size = 64 * 1024
        while True:
            chunk = resp.read(chunk_size)
            if not chunk:
                break
            out_f.write(chunk)
            downloaded += len(chunk)
            if progress_cb and total > 0:
                pct = int(downloaded * 100 / total)
                if pct >= last_pct + 10 or pct == 100:
                    last_pct = pct
                    progress_cb(f"Downloading cloudflared.exe... {pct}% ({downloaded // (1024 * 1024)} MB)")

    if os.name != "nt":
        dest.chmod(0o755)

    if progress_cb:
        progress_cb(f"cloudflared installed at {dest}")
    return dest


# ---------------------------------------------------------------------------
# Helper: XAMPP Local Server Health Check & Clipboard
# ---------------------------------------------------------------------------
def check_local_cafeflow(origin: str = DEFAULT_LOCAL_ORIGIN) -> tuple[bool, str]:
    """Verify XAMPP Apache is running and serving CafeFlow locally."""
    test_url = f"{origin.rstrip('/')}{CAFEFLOW_SUBPATH}/login.php"
    try:
        req = urllib.request.Request(test_url, headers={"User-Agent": "CafeFlow-HealthCheck"})
        with urllib.request.urlopen(req, timeout=3) as resp:
            if 200 <= resp.status < 400:
                return True, f"XAMPP Online ({test_url})"
            return False, f"HTTP {resp.status} from {test_url}"
    except Exception as exc:
        return False, f"XAMPP Apache unreachable at {test_url} ({exc})"


def copy_to_clipboard(text: str) -> bool:
    """Copy text to the OS clipboard."""
    try:
        if os.name == "nt":
            proc = subprocess.Popen(
                ["clip"],
                stdin=subprocess.PIPE,
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
            )
            proc.communicate(input=text.encode("utf-8"))
            return proc.returncode == 0
    except Exception:
        pass
    return False


# ---------------------------------------------------------------------------
# Helper: Persistent Tunnel Process State (CLI & GUI interoperability)
# ---------------------------------------------------------------------------
def load_state() -> dict:
    if not STATE_FILE.is_file():
        return {}
    try:
        return json.loads(STATE_FILE.read_text(encoding="utf-8"))
    except Exception:
        return {}


def save_state(data: dict) -> None:
    try:
        STATE_FILE.write_text(json.dumps(data, indent=2), encoding="utf-8")
    except Exception:
        pass


def clear_state() -> None:
    try:
        if STATE_FILE.is_file():
            STATE_FILE.unlink()
    except Exception:
        pass


def is_pid_running(pid: int) -> bool:
    if pid <= 0:
        return False
    if os.name == "nt":
        try:
            out = subprocess.check_output(
                ["tasklist", "/FI", f"PID eq {pid}", "/NH"],
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                text=True,
                stderr=subprocess.DEVNULL,
            )
            return str(pid) in out and "cloudflared" in out.lower()
        except Exception:
            return False
    else:
        try:
            os.kill(pid, 0)
            return True
        except OSError:
            return False


def stop_existing_tunnel() -> bool:
    """Stop any running cloudflared process tracked in STATE_FILE."""
    st = load_state()
    pid = int(st.get("pid", 0) or 0)
    stopped = False
    if pid and is_pid_running(pid):
        try:
            if os.name == "nt":
                subprocess.run(
                    ["taskkill", "/F", "/T", "/PID", str(pid)],
                    creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                    stdout=subprocess.DEVNULL,
                    stderr=subprocess.DEVNULL,
                )
            else:
                os.kill(pid, signal.SIGTERM)
            stopped = True
        except Exception:
            pass
    clear_state()
    return stopped


# ---------------------------------------------------------------------------
# CLI Mode (`on`, `off`, `status`)
# ---------------------------------------------------------------------------
def run_cli(action: str, origin: str = DEFAULT_LOCAL_ORIGIN, auto_copy: bool = True) -> int:
    action = action.lower().strip()

    if action == "status":
        st = load_state()
        pid = int(st.get("pid", 0) or 0)
        if pid and is_pid_running(pid):
            base_url = st.get("base_url", "")
            pos_url = f"{base_url}{CAFEFLOW_SUBPATH}/"
            login_url = f"{base_url}{CAFEFLOW_SUBPATH}/login.php"
            print("============================================================")
            print("  CAFEFLOW CLOUDFLARE TUNNEL: [ONLINE / ACTIVE]")
            print("============================================================")
            print(f"  PID        : {pid}")
            print(f"  Started At : {st.get('started_at', 'Unknown')}")
            print(f"  POS Link   : {pos_url}")
            print(f"  Login Link : {login_url}")
            print("============================================================")
            return 0
        else:
            clear_state()
            print("============================================================")
            print("  CAFEFLOW CLOUDFLARE TUNNEL: [OFFLINE]")
            print("============================================================")
            return 1

    if action == "off":
        was_running = stop_existing_tunnel()
        if was_running:
            print("[OK] Cloudflare temporary tunnel stopped (OFF).")
        else:
            print("[INFO] No active CafeFlow Cloudflare tunnel was running.")
        return 0

    if action == "on":
        st = load_state()
        pid = int(st.get("pid", 0) or 0)
        if pid and is_pid_running(pid):
            base_url = st.get("base_url", "")
            pos_url = f"{base_url}{CAFEFLOW_SUBPATH}/"
            print(f"[INFO] Tunnel is already ONLINE (PID {pid}): {pos_url}")
            if auto_copy and pos_url:
                copy_to_clipboard(pos_url)
                print("[OK] Copied POS link to clipboard!")
            return 0

        cf_bin = find_cloudflared()
        if not cf_bin:
            cf_bin = download_cloudflared(progress_cb=lambda m: print(f"  {m}"))

        ok, msg = check_local_cafeflow(origin)
        if not ok:
            print(f"[WARNING] {msg}")
            print("          Make sure XAMPP Apache & MySQL are started!")

        print(f"[*] Starting Cloudflare Quick Tunnel for {origin}{CAFEFLOW_SUBPATH}/ ...")
        creation_flags = 0
        if os.name == "nt":
            creation_flags = (
                getattr(subprocess, "CREATE_NEW_PROCESS_GROUP", 0)
                | getattr(subprocess, "CREATE_NO_WINDOW", 0)
            )

        found_url = None
        proc = None
        max_attempts = 5
        for attempt in range(1, max_attempts + 1):
            log_fh = open(LOG_FILE, "w", encoding="utf-8")
            proc = subprocess.Popen(
                [
                    str(cf_bin),
                    "tunnel",
                    "--url",
                    origin,
                    "--protocol",
                    "http2",
                    "--no-autoupdate",
                ],
                stdout=log_fh,
                stderr=subprocess.STDOUT,
                stdin=subprocess.DEVNULL,
                creationflags=creation_flags,
            )

            # Wait up to 22 seconds for the *.trycloudflare.com URL to appear in log
            deadline = time.time() + 22.0
            rate_limited = False
            while time.time() < deadline:
                try:
                    content = LOG_FILE.read_text(encoding="utf-8", errors="ignore")
                    match = TRYCLOUDFLARE_REGEX.search(content)
                    if match:
                        found_url = match.group(0)
                        break
                    if "status 429" in content or "Tunnelstore request failed" in content:
                        rate_limited = True
                        break
                except Exception:
                    pass
                if proc.poll() is not None:
                    break
                time.sleep(0.35)

            if found_url:
                break

            try:
                proc.terminate()
            except Exception:
                pass
            log_fh.close()

            if attempt < max_attempts:
                wait_s = 2.0 * attempt
                reason = "Cloudflare edge rate-limit (429)" if rate_limited else "no URL yet"
                print(f"  [Retry {attempt}/{max_attempts}] {reason} — retrying in {wait_s:.0f}s...")
                time.sleep(wait_s)

        if not found_url or proc is None:
            print("[ERROR] Failed to obtain trycloudflare.com URL after retries. Check cloudflare_deploy/.cf_tunnel.log.")
            return 1

        pos_url = f"{found_url}{CAFEFLOW_SUBPATH}/"
        login_url = f"{found_url}{CAFEFLOW_SUBPATH}/login.php"
        save_state(
            {
                "pid": proc.pid,
                "base_url": found_url,
                "pos_url": pos_url,
                "login_url": login_url,
                "origin": origin,
                "started_at": time.strftime("%Y-%m-%d %H:%M:%S"),
            }
        )

        copied = copy_to_clipboard(pos_url) if auto_copy else False
        print("\n============================================================")
        print("  CAFEFLOW TEMPORARY CLOUDFLARE DEPLOYMENT IS LIVE (ON)")
        print("============================================================")
        print(f"  Tunnel PID      : {proc.pid}")
        print(f"  CafeFlow Link   : {pos_url}")
        print(f"  Direct Login    : {login_url}")
        if copied:
            print("  Clipboard       : [COPIED TO CLIPBOARD AUTOMATICALLY]")
        print("------------------------------------------------------------")
        print("  To stop tunnel  : python cloudflare_deploy/cloudflare_deploy.py off")
        print("============================================================\n")
        return 0

    print(f"Unknown CLI action: {action}")
    return 1


# ---------------------------------------------------------------------------
# Desktop GUI Mode (Tkinter) — Espresso & Caramel CafeFlow Theme
# ---------------------------------------------------------------------------
def run_gui(origin: str = DEFAULT_LOCAL_ORIGIN) -> int:
    import tkinter as tk
    from tkinter import ttk, messagebox

    # CafeFlow Design Tokens
    BG_DARK = "#1B1512"
    SURFACE = "#261E1A"
    SURFACE_CARD = "#312621"
    BORDER = "#46372F"
    TEXT_MAIN = "#F7EFEA"
    TEXT_MUTED = "#B8A598"
    CARAMEL = "#D98A4E"
    CARAMEL_HOVER = "#E59A60"
    GREEN = "#2E9B68"
    GREEN_BG = "#193829"
    RED = "#D9534F"
    RED_BG = "#3B1A1A"
    AMBER = "#E0A82E"

    root = tk.Tk()
    root.title("CafeFlow POS · Cloudflare Temporary Deployment")
    root.geometry("680x560")
    root.minsize(640, 520)
    root.configure(bg=BG_DARK)

    # State variables
    tunnel_proc: Optional[subprocess.Popen] = None
    is_running = False
    is_starting = False
    log_queue: queue.Queue[str] = queue.Queue()

    pos_url_var = tk.StringVar(value="Tunnel is offline — click 'TURN ONDEPLOYMENT' to generate link")
    login_url_var = tk.StringVar(value="—")
    status_text_var = tk.StringVar(value="OFFLINE")
    xampp_status_var = tk.StringVar(value="Checking XAMPP Apache...")
    auto_copy_var = tk.BooleanVar(value=True)
    copy_feedback_var = tk.StringVar(value="")

    # -----------------------------------------------------------------------
    # Layout Construction
    # -----------------------------------------------------------------------
    header_frame = tk.Frame(root, bg=SURFACE, padx=22, pady=16, highlightthickness=1, highlightbackground=BORDER)
    header_frame.pack(fill="x")

    title_col = tk.Frame(header_frame, bg=SURFACE)
    title_col.pack(side="left", fill="x", expand=True)

    tk.Label(
        title_col,
        text="CAFEFLOW · CLOUDFLARE QUICK DEPLOY",
        font=("Segoe UI", 13, "bold"),
        fg=TEXT_MAIN,
        bg=SURFACE,
        anchor="w",
    ).pack(fill="x")

    tk.Label(
        title_col,
        text="Instant public HTTPS link (*.trycloudflare.com) for live POS presentation & mobile testing",
        font=("Segoe UI", 9),
        fg=TEXT_MUTED,
        bg=SURFACE,
        anchor="w",
    ).pack(fill="x", pady=(2, 0))

    status_badge = tk.Label(
        header_frame,
        textvariable=status_text_var,
        font=("Segoe UI", 9, "bold"),
        fg=TEXT_MUTED,
        bg=SURFACE_CARD,
        padx=12,
        pady=5,
    )
    status_badge.pack(side="right")

    body = tk.Frame(root, bg=BG_DARK, padx=22, pady=16)
    body.pack(fill="both", expand=True)

    # Top Control Card (XAMPP Check + Master ON/OFF Toggle)
    ctrl_card = tk.Frame(body, bg=SURFACE, padx=18, pady=16, highlightthickness=1, highlightbackground=BORDER)
    ctrl_card.pack(fill="x", pady=(0, 14))

    ctrl_top = tk.Frame(ctrl_card, bg=SURFACE)
    ctrl_top.pack(fill="x")

    xampp_lbl = tk.Label(
        ctrl_top,
        textvariable=xampp_status_var,
        font=("Segoe UI", 9, "bold"),
        fg=AMBER,
        bg=SURFACE,
        anchor="w",
    )
    xampp_lbl.pack(side="left")

    auto_chk = tk.Checkbutton(
        ctrl_top,
        text="Auto-copy link when tunnel starts",
        variable=auto_copy_var,
        font=("Segoe UI", 9),
        fg=TEXT_MUTED,
        bg=SURFACE,
        activebackground=SURFACE,
        activeforeground=TEXT_MAIN,
        selectcolor=SURFACE_CARD,
    )
    auto_chk.pack(side="right")

    btn_row = tk.Frame(ctrl_card, bg=SURFACE)
    btn_row.pack(fill="x", pady=(12, 0))

    toggle_btn = tk.Button(
        btn_row,
        text="▶  TURN ON DEPLOYMENT (START TUNNEL)",
        font=("Segoe UI", 11, "bold"),
        fg="#1B120C",
        bg=CARAMEL,
        activebackground=CARAMEL_HOVER,
        activeforeground="#1B120C",
        relief="flat",
        cursor="hand2",
        padx=18,
        pady=10,
    )
    toggle_btn.pack(fill="x")

    # Links Card (Easy Copy & Open)
    links_card = tk.Frame(body, bg=SURFACE, padx=18, pady=14, highlightthickness=1, highlightbackground=BORDER)
    links_card.pack(fill="x", pady=(0, 14))

    link_hdr = tk.Frame(links_card, bg=SURFACE)
    link_hdr.pack(fill="x", pady=(0, 6))

    tk.Label(
        link_hdr,
        text="PUBLIC DEPLOYMENT LINKS (HTTPS)",
        font=("Segoe UI", 9, "bold"),
        fg=TEXT_MUTED,
        bg=SURFACE,
    ).pack(side="left")

    feedback_lbl = tk.Label(
        link_hdr,
        textvariable=copy_feedback_var,
        font=("Segoe UI", 9, "bold"),
        fg=GREEN,
        bg=SURFACE,
    )
    feedback_lbl.pack(side="right")

    # 1. Primary POS Link Row
    tk.Label(
        links_card,
        text="CafeFlow App URL:",
        font=("Segoe UI", 9),
        fg=TEXT_MAIN,
        bg=SURFACE,
        anchor="w",
    ).pack(fill="x")

    pos_row = tk.Frame(links_card, bg=SURFACE)
    pos_row.pack(fill="x", pady=(4, 10))

    pos_entry = tk.Entry(
        pos_row,
        textvariable=pos_url_var,
        font=("Consolas", 10, "bold"),
        fg=CARAMEL,
        bg=SURFACE_CARD,
        readonlybackground=SURFACE_CARD,
        relief="flat",
        state="readonly",
         highlightthickness=1,
        highlightbackground=BORDER,
    )
    pos_entry.pack(side="left", fill="x", expand=True, ipady=7, padx=(0, 8))

    def flash_feedback(msg: str) -> None:
        copy_feedback_var.set(msg)
        root.after(2600, lambda: copy_feedback_var.set(""))

    def on_copy_pos() -> None:
        val = pos_url_var.get()
        if not val.startswith("https://"):
            flash_feedback("Start tunnel first!")
            return
        root.clipboard_clear()
        root.clipboard_append(val)
        root.update()
        copy_to_clipboard(val)
        flash_feedback("✓ Copied POS Link to Clipboard!")

    def on_open_browser() -> None:
        val = pos_url_var.get()
        if not val.startswith("https://"):
            flash_feedback("Start tunnel first!")
            return
        webbrowser.open(val)

    copy_pos_btn = tk.Button(
        pos_row,
        text="📋 Copy Link",
        command=on_copy_pos,
        font=("Segoe UI", 9, "bold"),
        fg="#1B120C",
        bg=CARAMEL,
        activebackground=CARAMEL_HOVER,
        relief="flat",
        cursor="hand2",
        padx=12,
        pady=6,
    )
    copy_pos_btn.pack(side="left", padx=(0, 6))

    open_btn = tk.Button(
        pos_row,
        text="↗ Open",
        command=on_open_browser,
        font=("Segoe UI", 9, "bold"),
        fg=TEXT_MAIN,
        bg=SURFACE_CARD,
        activebackground=BORDER,
        activeforeground=TEXT_MAIN,
        relief="flat",
        cursor="hand2",
        padx=10,
        pady=6,
    )
    open_btn.pack(side="left")

    # 2. Direct Login Link Row
    tk.Label(
        links_card,
        text="Direct Login Page URL:",
        font=("Segoe UI", 9),
        fg=TEXT_MAIN,
        bg=SURFACE,
        anchor="w",
    ).pack(fill="x")

    login_row = tk.Frame(links_card, bg=SURFACE)
    login_row.pack(fill="x", pady=(4, 2))

    login_entry = tk.Entry(
        login_row,
        textvariable=login_url_var,
        font=("Consolas", 9),
        fg=TEXT_MUTED,
        bg=SURFACE_CARD,
        readonlybackground=SURFACE_CARD,
        relief="flat",
        state="readonly",
        highlightthickness=1,
        highlightbackground=BORDER,
    )
    login_entry.pack(side="left", fill="x", expand=True, ipady=6, padx=(0, 8))

    def on_copy_login() -> None:
        val = login_url_var.get()
        if not val.startswith("https://"):
            flash_feedback("Start tunnel first!")
            return
        root.clipboard_clear()
        root.clipboard_append(val)
        root.update()
        copy_to_clipboard(val)
        flash_feedback("✓ Copied Login Link!")

    copy_login_btn = tk.Button(
        login_row,
        text="📋 Copy Login",
        command=on_copy_login,
        font=("Segoe UI", 9),
        fg=TEXT_MAIN,
        bg=SURFACE_CARD,
        activebackground=BORDER,
        activeforeground=TEXT_MAIN,
        relief="flat",
        cursor="hand2",
        padx=12,
        pady=5,
    )
    copy_login_btn.pack(side="left")

    # Live Activity Log Panel
    log_frame = tk.Frame(body, bg=SURFACE, padx=14, pady=10, highlightthickness=1, highlightbackground=BORDER)
    log_frame.pack(fill="both", expand=True)

    tk.Label(
        log_frame,
        text="CLOUDFLARE TUNNEL LOG",
        font=("Segoe UI", 8, "bold"),
        fg=TEXT_MUTED,
        bg=SURFACE,
        anchor="w",
    ).pack(fill="x", pady=(0, 4))

    log_text = tk.Text(
        log_frame,
        height=7,
        font=("Consolas", 8),
        fg=TEXT_MUTED,
        bg="#14100E",
        relief="flat",
        wrap="word",
        state="disabled",
    )
    log_text.pack(fill="both", expand=True)

    def append_log(line: str) -> None:
        log_text.configure(state="normal")
        log_text.insert("end", line.rstrip() + "\n")
        log_text.see("end")
        log_text.configure(state="disabled")

    # -----------------------------------------------------------------------
    # UI State Synchronizer
    # -----------------------------------------------------------------------
    def set_ui_state(state: str, base_url: str = "") -> None:
        nonlocal is_running, is_starting
        if state == "STARTING":
            is_starting = True
            is_running = False
            status_text_var.set("● STARTING...")
            status_badge.configure(fg=AMBER, bg=SURFACE_CARD)
            toggle_btn.configure(
                text="⏳  INITIALIZING CLOUDFLARE TUNNEL...",
                bg=SURFACE_CARD,
                fg=TEXT_MUTED,
                state="disabled",
            )
            pos_url_var.set("Negotiating Edge Tunnel with Cloudflare...")
            login_url_var.set("Please wait...")
        elif state == "ONLINE":
            is_starting = False
            is_running = True
            pos_link = f"{base_url}{CAFEFLOW_SUBPATH}/"
            login_link = f"{base_url}{CAFEFLOW_SUBPATH}/login.php"
            status_text_var.set("● LIVE ON CLOUDFLARE")
            status_badge.configure(fg="#58E6A9", bg=GREEN_BG)
            toggle_btn.configure(
                text="■  TURN OFF DEPLOYMENT (STOP TUNNEL)",
                bg=RED,
                activebackground="#E26A66",
                fg="#FFFFFF",
                activeforeground="#FFFFFF",
                state="normal",
            )
            pos_url_var.set(pos_link)
            login_url_var.set(login_link)
            if auto_copy_var.get():
                root.clipboard_clear()
                root.clipboard_append(pos_link)
                root.update()
                copy_to_clipboard(pos_link)
                flash_feedback("✓ Live & Auto-Copied to Clipboard!")
        else:
            is_starting = False
            is_running = False
            status_text_var.set("● OFFLINE")
            status_badge.configure(fg=TEXT_MUTED, bg=SURFACE_CARD)
            toggle_btn.configure(
                text="▶  TURN ON DEPLOYMENT (START TUNNEL)",
                bg=CARAMEL,
                activebackground=CARAMEL_HOVER,
                fg="#1B120C",
                activeforeground="#1B120C",
                state="normal",
            )
            pos_url_var.set("Tunnel is offline — click 'TURN ON DEPLOYMENT' to generate link")
            login_url_var.set("—")

    # -----------------------------------------------------------------------
    # Background Workers
    # -----------------------------------------------------------------------
    def refresh_xampp_health() -> None:
        def _worker():
            ok, msg = check_local_cafeflow(origin)
            root.after(
                0,
                lambda: (
                    xampp_status_var.set(f"● {msg}"),
                    xampp_lbl.configure(fg=GREEN if ok else RED),
                ),
            )

        threading.Thread(target=_worker, daemon=True).start()

    def start_tunnel_async() -> None:
        nonlocal tunnel_proc
        if is_starting or is_running:
            return
        set_ui_state("STARTING")
        refresh_xampp_health()

        def _worker():
            nonlocal tunnel_proc
            try:
                stop_existing_tunnel()
                cf_bin = find_cloudflared()
                if not cf_bin:
                    log_queue.put("[Setup] cloudflared not found. Downloading standalone binary...")
                    cf_bin = download_cloudflared(progress_cb=lambda m: log_queue.put(f"[Setup] {m}"))

                creation_flags = getattr(subprocess, "CREATE_NO_WINDOW", 0) if os.name == "nt" else 0
                found = False
                max_attempts = 5

                for attempt in range(1, max_attempts + 1):
                    log_queue.put(
                        f"[Tunnel] Launching (attempt {attempt}/{max_attempts}): "
                        f"{cf_bin.name} tunnel --url {origin} --protocol http2"
                    )
                    proc = subprocess.Popen(
                        [
                            str(cf_bin),
                            "tunnel",
                            "--url",
                            origin,
                            "--protocol",
                            "http2",
                            "--no-autoupdate",
                        ],
                        stdout=subprocess.PIPE,
                        stderr=subprocess.STDOUT,
                        stdin=subprocess.DEVNULL,
                        text=True,
                        bufsize=1,
                        creationflags=creation_flags,
                    )
                    tunnel_proc = proc
                    rate_limited = False

                    assert proc.stdout is not None
                    for raw_line in proc.stdout:
                        line = raw_line.strip()
                        if line:
                            log_queue.put(line)
                        if not found:
                            m = TRYCLOUDFLARE_REGEX.search(line)
                            if m:
                                found = True
                                base_url = m.group(0)
                                save_state(
                                    {
                                        "pid": proc.pid,
                                        "base_url": base_url,
                                        "pos_url": f"{base_url}{CAFEFLOW_SUBPATH}/",
                                        "login_url": f"{base_url}{CAFEFLOW_SUBPATH}/login.php",
                                        "origin": origin,
                                        "started_at": time.strftime("%Y-%m-%d %H:%M:%S"),
                                    }
                                )
                                log_queue.put(f"[LIVE] Public URL Ready: {base_url}{CAFEFLOW_SUBPATH}/")
                                root.after(0, lambda u=base_url: set_ui_state("ONLINE", u))
                            elif "status 429" in line or "Tunnelstore request failed" in line:
                                rate_limited = True

                    proc.wait()
                    if found or not rate_limited or attempt == max_attempts:
                        break
                    wait_s = 2 * attempt
                    log_queue.put(f"[Retry] Cloudflare edge busy (429) — retrying in {wait_s}s...")
                    time.sleep(wait_s)

                log_queue.put("[Tunnel] Tunnel process stopped.")
                clear_state()
                root.after(0, lambda: set_ui_state("OFFLINE"))
            except Exception as exc:
                log_queue.put(f"[Error] {exc}")
                clear_state()
                root.after(0, lambda: set_ui_state("OFFLINE"))

        threading.Thread(target=_worker, daemon=True).start()

    def stop_tunnel() -> None:
        nonlocal tunnel_proc
        log_queue.put("[Tunnel] Stopping Cloudflare deployment...")
        if tunnel_proc and tunnel_proc.poll() is None:
            try:
                tunnel_proc.terminate()
            except Exception:
                pass
        stop_existing_tunnel()
        tunnel_proc = None
        set_ui_state("OFFLINE")

    def on_toggle_click() -> None:
        if is_running:
            stop_tunnel()
        else:
            start_tunnel_async()

    toggle_btn.configure(command=on_toggle_click)

    def poll_queues() -> None:
        while True:
            try:
                msg = log_queue.get_nowait()
                append_log(msg)
            except queue.Empty:
                break
        root.after(150, poll_queues)

    def on_close() -> None:
        if is_running:
            if messagebox.askyesno(
                "Stop Cloudflare Tunnel?",
                "The temporary Cloudflare deployment is currently LIVE.\n\n"
                "Do you want to turn OFF the tunnel before closing?",
            ):
                stop_tunnel()
        root.destroy()

    root.protocol("WM_DELETE_WINDOW", on_close)

    # Check if a background CLI tunnel is already active when opening the GUI
    st = load_state()
    existing_pid = int(st.get("pid", 0) or 0)
    if existing_pid and is_pid_running(existing_pid) and st.get("base_url"):
        append_log(f"[Attached] Detected active tunnel (PID {existing_pid})")
        set_ui_state("ONLINE", st["base_url"])
    else:
        append_log("[Ready] Click 'TURN ON DEPLOYMENT' to generate a temporary Cloudflare URL.")

    refresh_xampp_health()
    poll_queues()
    root.mainloop()
    return 0


# ---------------------------------------------------------------------------
# Entry Point
# ---------------------------------------------------------------------------
def main() -> int:
    parser = argparse.ArgumentParser(
        description="CafeFlow POS — Automated Cloudflare Temporary Tunnel Deployment Tool"
    )
    parser.add_argument(
        "action",
        nargs="?",
        choices=["gui", "on", "off", "status"],
        default="gui",
        help="Action: 'gui' (default window), 'on' (start in background), 'off' (stop), or 'status'",
    )
    parser.add_argument(
        "--origin",
        default=DEFAULT_LOCAL_ORIGIN,
        help="Local XAMPP Apache origin (default: http://localhost:80)",
    )
    parser.add_argument(
        "--no-copy",
        action="store_true",
        help="Do not automatically copy the generated URL to the clipboard in CLI mode",
    )
    args = parser.parse_args()

    if args.action == "gui":
        return run_gui(origin=args.origin)
    return run_cli(action=args.action, origin=args.origin, auto_copy=not args.no_copy)


if __name__ == "__main__":
    sys.exit(main())
