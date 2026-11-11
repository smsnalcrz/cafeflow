# -*- coding: utf-8 -*-
"""
core/excel_generator.py - Enterprise-Grade Multi-Sheet Excel (.xlsx) Designer for CafeFlow POS.
Generates an executive-level sales audit, itemized transaction ledger, product performance ranking,
and raw supplies inventory stock health report with native Excel charts and print setups.
"""

import sys, os, argparse, datetime
import pymysql
import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter
from openpyxl.chart import BarChart, DoughnutChart, Reference

# ==============================================================================
# COLOR PALETTE & DESIGN SYSTEM (Modern Executive Coffee Theme)
# ==============================================================================
COLOR_ESPRESSO       = "1F1610"  # Rich dark roast primary banner
COLOR_ESPRESSO_LIGHT = "2D2017"
COLOR_CARAMEL        = "B46B1C"  # Warm artisan caramel accent
COLOR_CARAMEL_LIGHT  = "D98A32"
COLOR_FOREST         = "1B4D3E"  # Rich forest green
COLOR_PARCHMENT      = "F4ECE1"  # Warm header and totals background
COLOR_CREAM          = "FAF7F2"  # Clean alternating zebra row
COLOR_CARD_BG        = "F8F5EE"  # KPI Card background
COLOR_CARD_BORDER    = "DFD4C4"  # KPI Card border
COLOR_BORDER         = "E4D9CC"  # Subtle table cell border
COLOR_BORDER_STRONG  = "1F1610"  # Accounting double bottom border
COLOR_WHITE          = "FFFFFF"
COLOR_INK            = "1E1E1E"  # High-contrast readable text
COLOR_MUTED          = "64748B"  # Secondary label text

# Status Badges
STATUS_COMPLETED_BG = "E6F4EA"
STATUS_COMPLETED_FG = "137333"
STATUS_PENDING_BG   = "FEF7E0"
STATUS_PENDING_FG   = "B06000"
STATUS_VOIDED_BG    = "FCE8E6"
STATUS_VOIDED_FG    = "C5221F"

STOCK_OK_BG  = "E6F4EA"
STOCK_OK_FG  = "137333"
STOCK_LOW_BG = "FEF7E0"
STOCK_LOW_FG = "B06000"
STOCK_OUT_BG = "FCE8E6"
STOCK_OUT_FG = "C5221F"

FMT_CURRENCY = '"₱"#,##0.00'
FMT_INTEGER  = '#,##0'
FMT_DECIMAL  = '#,##0.00'
FMT_PERCENT  = '0.0%'

def get_db_connection():
    return pymysql.connect(
        host='127.0.0.1', user='root', password='', database='cafeflow',
        cursorclass=pymysql.cursors.DictCursor
    )

def setup_page(ws, orientation='portrait', title_rows=None):
    """Applies enterprise-level print setup, margins, gridlines, and scaling."""
    ws.views.sheetView[0].showGridLines = True
    ws.page_setup.orientation = ws.ORIENTATION_LANDSCAPE if orientation == 'landscape' else ws.ORIENTATION_PORTRAIT
    ws.page_setup.paperSize = ws.PAPERSIZE_LETTER
    ws.sheet_properties.pageSetUpPr.fitToPage = True
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 0
    ws.page_margins.left = 0.5
    ws.page_margins.right = 0.5
    ws.page_margins.top = 0.75
    ws.page_margins.bottom = 0.75
    ws.page_margins.header = 0.3
    ws.page_margins.footer = 0.3
    if title_rows:
        ws.print_title_rows = title_rows

def auto_fit_columns(ws, min_col=1, max_col=None, start_row=4, min_width=12, padding=4, custom_widths=None):
    """Calculates column widths based on cell text content and number formats."""
    custom_widths = custom_widths or {}
    max_c = max_col or ws.max_column
    for col_idx in range(min_col, max_c + 1):
        col_letter = get_column_letter(col_idx)
        if col_letter in custom_widths:
            ws.column_dimensions[col_letter].width = custom_widths[col_letter]
            continue
        max_len = 0
        for row in range(start_row, ws.max_row + 1):
            cell = ws.cell(row=row, column=col_idx)
            val = cell.value
            if val is None:
                continue
            val_str = str(val)
            if cell.number_format == FMT_CURRENCY and isinstance(val, (int, float)):
                val_str = f"₱{val:,.2f}"
            elif cell.number_format == FMT_PERCENT and isinstance(val, (int, float)):
                val_str = f"{val * 100:.1f}%"
            elif cell.number_format == FMT_INTEGER and isinstance(val, (int, float)):
                val_str = f"{val:,}"
            elif cell.number_format == FMT_DECIMAL and isinstance(val, (int, float)):
                val_str = f"{val:,.2f}"
            max_len = max(max_len, len(val_str))
        ws.column_dimensions[col_letter].width = max(max_len + padding, min_width)

def generate_sales_workbook(output_path, start_date=None, end_date=None, single_date=None, is_all=False):
    conn = get_db_connection()

    where_clause = ""
    params = []
    period_title = ""

    if is_all:
        where_clause = "WHERE 1=1"
        period_title = "All Available Transactions (Full Historical Audit)"
    elif start_date and end_date:
        where_clause = "WHERE t.timestamp >= %s AND t.timestamp < %s"
        params.append(f"{start_date} 00:00:00")
        end_dt = datetime.datetime.strptime(end_date, "%Y-%m-%d") + datetime.timedelta(days=1)
        params.append(end_dt.strftime("%Y-%m-%d 00:00:00"))
        period_title = f"{start_date} to {end_date}"
    elif single_date:
        where_clause = "WHERE t.timestamp >= %s AND t.timestamp < %s"
        params.append(f"{single_date} 00:00:00")
        end_dt = datetime.datetime.strptime(single_date, "%Y-%m-%d") + datetime.timedelta(days=1)
        params.append(end_dt.strftime("%Y-%m-%d 00:00:00"))
        period_title = single_date
    else:
        today_str = datetime.date.today().strftime("%Y-%m-%d")
        where_clause = "WHERE t.timestamp >= %s AND t.timestamp < %s"
        params.append(f"{today_str} 00:00:00")
        end_dt = datetime.date.today() + datetime.timedelta(days=1)
        params.append(end_dt.strftime("%Y-%m-%d 00:00:00"))
        period_title = f"Today ({today_str})"

    # Fetch Transactions & Line Items
    tx_sql = f"""
        SELECT t.transaction_id, t.total_amount, t.amount_paid, t.change_due,
               t.subtotal, t.tax, t.discount, t.discount_type, t.discount_rate,
               t.discount_id_no, t.discount_reason,
               t.status, t.payment_method, t.reference_number,
               t.cashier_user_id, u_c.full_name AS cashier_name, u_c.username AS cashier_user,
               t.cancelled_by_user_id, u_v.full_name AS canceller_name, u_v.username AS canceller_user,
               t.cancelled_at, t.cancellation_reason, t.timestamp,
               ti.id AS item_row_id, ti.product_id, ti.product_name, ti.quantity, ti.unit_price,
               ti.size_code, ti.size_label, ti.size_oz,
               mp.name AS current_name, mp.category AS current_category, mp.price AS current_price
          FROM transactions t
          JOIN transaction_items ti ON ti.transaction_id = t.transaction_id
          LEFT JOIN users u_c ON u_c.user_id = t.cashier_user_id
          LEFT JOIN users u_v ON u_v.user_id = t.cancelled_by_user_id
          LEFT JOIN menu_products mp ON mp.product_id = ti.product_id
         {where_clause}
         ORDER BY t.timestamp ASC, t.transaction_id ASC, ti.id ASC
    """

    # Fetch Inventory Items
    inv_sql = """
        SELECT item_id, name, current_stock, unit_measure, restock_threshold, is_active
          FROM inventory
         ORDER BY is_active DESC, (current_stock <= restock_threshold) DESC, name ASC
    """

    with conn.cursor() as cur:
        cur.execute(tx_sql, params)
        tx_rows = cur.fetchall()
        cur.execute(inv_sql)
        inv_rows = cur.fetchall()

    wb = openpyxl.Workbook()

    # Reusable Styles
    font_main     = Font(name="Segoe UI", size=9.5, color=COLOR_INK)
    font_bold     = Font(name="Segoe UI", size=9.5, bold=True, color=COLOR_INK)
    font_title    = Font(name="Segoe UI", size=15, bold=True, color=COLOR_WHITE)
    font_subtitle = Font(name="Segoe UI", size=9, italic=True, color="EBE3D5")
    font_sec_hdr  = Font(name="Segoe UI", size=11, bold=True, color=COLOR_ESPRESSO)
    font_tbl_hdr  = Font(name="Segoe UI", size=10, bold=True, color=COLOR_WHITE)
    font_total    = Font(name="Segoe UI", size=10, bold=True, color=COLOR_ESPRESSO)
    font_kpi_num  = Font(name="Segoe UI", size=17, bold=True, color=COLOR_ESPRESSO)
    font_kpi_lbl  = Font(name="Segoe UI", size=8.5, bold=True, color=COLOR_MUTED)

    fill_espresso  = PatternFill(start_color=COLOR_ESPRESSO, end_color=COLOR_ESPRESSO, fill_type="solid")
    fill_caramel   = PatternFill(start_color=COLOR_CARAMEL, end_color=COLOR_CARAMEL, fill_type="solid")
    fill_parchment = PatternFill(start_color=COLOR_PARCHMENT, end_color=COLOR_PARCHMENT, fill_type="solid")
    fill_cream     = PatternFill(start_color=COLOR_CREAM, end_color=COLOR_CREAM, fill_type="solid")
    fill_card      = PatternFill(start_color=COLOR_CARD_BG, end_color=COLOR_CARD_BG, fill_type="solid")

    border_thin = Border(
        left=Side(style='thin', color=COLOR_BORDER),
        right=Side(style='thin', color=COLOR_BORDER),
        top=Side(style='thin', color=COLOR_BORDER),
        bottom=Side(style='thin', color=COLOR_BORDER)
    )
    border_total = Border(
        top=Side(style='thin', color=COLOR_ESPRESSO),
        bottom=Side(style='double', color=COLOR_ESPRESSO)
    )
    border_card = Border(
        left=Side(style='thin', color=COLOR_CARD_BORDER),
        right=Side(style='thin', color=COLOR_CARD_BORDER),
        top=Side(style='thin', color=COLOR_CARD_BORDER),
        bottom=Side(style='thin', color=COLOR_CARD_BORDER)
    )

    align_left   = Alignment(horizontal='left', vertical='center')
    align_center = Alignment(horizontal='center', vertical='center')
    align_right  = Alignment(horizontal='right', vertical='center')

    # Aggregate Metrics Calculation
    total_sales = 0.0
    total_orders = 0
    total_discounts = 0.0
    cash_sales = 0.0
    gcash_sales = 0.0
    cash_tendered = 0.0
    change_dispensed = 0.0
    total_subtotal_gross = 0.0

    seen_tx = set()
    cash_tx_ids = set()
    gcash_tx_ids = set()
    category_mix = {}
    product_mix = {}

    for r in tx_rows:
        tId = r['transaction_id']
        total = float(r['total_amount'] or 0)
        disc = float(r['discount'] or 0)
        paid = float(r['amount_paid'] or total)
        chg = float(r['change_due'] or 0)
        status = r['status']
        pay_method = r['payment_method']
        sub_val = float(r.get('subtotal') or (total + disc))

        qty = int(r['quantity'] or 1)
        price = float(r['unit_price'] or r['current_price'] or 0)
        line_total = round(qty * price, 2)
        prod_name = r['product_name'] or r['current_name'] or f"Item #{r['product_id']}"
        cat_name = r['current_category'] or 'General'

        if tId not in seen_tx:
            seen_tx.add(tId)
            if status == 'Completed':
                total_orders += 1
                total_sales += total
                total_discounts += disc
                total_subtotal_gross += sub_val
                if pay_method == 'Cash':
                    cash_tx_ids.add(tId)
                    cash_sales += total
                    cash_tendered += paid
                    change_dispensed += chg
                elif pay_method == 'GCash':
                    gcash_tx_ids.add(tId)
                    gcash_sales += total

        if status == 'Completed':
            if cat_name not in category_mix:
                category_mix[cat_name] = {'qty': 0, 'sales': 0.0}
            category_mix[cat_name]['qty'] += qty
            category_mix[cat_name]['sales'] += line_total

            if prod_name not in product_mix:
                product_mix[prod_name] = {'cat': cat_name, 'qty': 0, 'sales': 0.0, 'price': price}
            product_mix[prod_name]['qty'] += qty
            product_mix[prod_name]['sales'] += line_total

    aov = (total_sales / total_orders) if total_orders > 0 else 0.0
    now_str = datetime.datetime.now().strftime("%B %d, %Y at %I:%M %p")

    # =========================================================================
    # SHEET 1: 📊 Executive Dashboard
    # =========================================================================
    ws1 = wb.active
    ws1.title = "Executive Dashboard"
    ws1.sheet_properties.tabColor = COLOR_ESPRESSO
    setup_page(ws1, orientation='portrait')

    ws1.merge_cells("A1:G1")
    ws1.merge_cells("A2:G2")
    ws1.merge_cells("A3:G3")

    ws1["A1"] = "☕ DK CAFE & CAFEFLOW POS · EXECUTIVE SALES & FINANCIAL AUDIT"
    ws1["A1"].font = font_title
    ws1["A1"].fill = fill_espresso
    ws1["A1"].alignment = align_center
    ws1.row_dimensions[1].height = 36

    ws1["A2"] = f"Reporting Period: {period_title} | Store: DK Cafe (Matabungkay, Lian, Batangas)"
    ws1["A2"].font = font_subtitle
    ws1["A2"].fill = fill_espresso
    ws1["A2"].alignment = align_center
    ws1.row_dimensions[2].height = 18

    ws1["A3"] = f"Official Performance Report | Exported: {now_str} | Currency: Philippine Peso (PHP ₱) | 0% Non-VAT"
    ws1["A3"].font = font_subtitle
    ws1["A3"].fill = fill_espresso
    ws1["A3"].alignment = align_center
    ws1.row_dimensions[3].height = 20

    ws1.row_dimensions[4].height = 12

    # 6 KPI Summary Cards
    kpis = [
        ("A", "B", "TOTAL COMPLETED SALES", total_sales, FMT_CURRENCY, "Gross settled revenue"),
        ("C", "C", "COMPLETED ORDERS", total_orders, FMT_INTEGER, "Customer receipts"),
        ("D", "D", "AVERAGE ORDER VALUE", aov, FMT_CURRENCY, "Avg ticket size"),
        ("E", "E", "TOTAL DISCOUNTS", total_discounts, FMT_CURRENCY, "20% SC/PWD & Promos"),
        ("F", "F", "CASH DRAWER TENDER", cash_tendered - change_dispensed, FMT_CURRENCY, "Net drawer cash"),
        ("G", "G", "GCASH DIGITAL SALES", gcash_sales, FMT_CURRENCY, "E-Wallet settlement")
    ]

    ws1.row_dimensions[5].height = 17
    ws1.row_dimensions[6].height = 32
    ws1.row_dimensions[7].height = 16

    for c_start, c_end, lbl, val, fmt, sub in kpis:
        if c_start != c_end:
            ws1.merge_cells(f"{c_start}5:{c_end}5")
            ws1.merge_cells(f"{c_start}6:{c_end}6")
            ws1.merge_cells(f"{c_start}7:{c_end}7")

        ws1[f"{c_start}5"] = lbl
        ws1[f"{c_start}5"].font = font_kpi_lbl
        ws1[f"{c_start}5"].alignment = align_center

        ws1[f"{c_start}6"] = val
        ws1[f"{c_start}6"].font = font_kpi_num
        ws1[f"{c_start}6"].number_format = fmt
        ws1[f"{c_start}6"].alignment = align_center

        ws1[f"{c_start}7"] = sub
        ws1[f"{c_start}7"].font = Font(name="Segoe UI", size=7.5, italic=True, color=COLOR_MUTED)
        ws1[f"{c_start}7"].alignment = align_center

        cols_range = [c_start] if c_start == c_end else [c_start, c_end]
        for c in cols_range:
            for r_idx in [5, 6, 7]:
                cell = ws1[f"{c}{r_idx}"]
                cell.fill = fill_card
                cell.border = border_card

    ws1.row_dimensions[8].height = 16

    # Payment Reconciliation Table (Left: A9:C13)
    ws1["A9"] = "💳 PAYMENT METHOD RECONCILIATION"
    ws1["A9"].font = font_sec_hdr
    ws1.merge_cells("A9:C9")

    # Revenue Classification Table (Right: E9:G15)
    ws1["E9"] = "🏷️ REVENUE & DISCOUNT SUMMARY"
    ws1["E9"].font = font_sec_hdr
    ws1.merge_cells("E9:G9")

    for c, h in [("A", "Payment Channel"), ("B", "Orders"), ("C", "Revenue (₱)")]:
        cell = ws1[f"{c}10"]
        cell.value = h
        cell.font = font_tbl_hdr
        cell.fill = fill_espresso
        cell.alignment = align_left if c == "A" else align_right
        cell.border = border_thin

    pay_data = [
        ("💵 Cash at Counter", len(cash_tx_ids), cash_sales),
        ("📱 GCash Digital E-Wallet", len(gcash_tx_ids), gcash_sales)
    ]
    for idx, (p_name, p_cnt, p_rev) in enumerate(pay_data, start=11):
        ws1[f"A{idx}"] = p_name
        ws1[f"B{idx}"] = p_cnt
        ws1[f"C{idx}"] = p_rev
        ws1[f"A{idx}"].font = font_main
        ws1[f"B{idx}"].font = font_main
        ws1[f"C{idx}"].font = font_bold
        ws1[f"B{idx}"].number_format = FMT_INTEGER
        ws1[f"C{idx}"].number_format = FMT_CURRENCY
        ws1[f"A{idx}"].border = border_thin
        ws1[f"B{idx}"].border = border_thin
        ws1[f"C{idx}"].border = border_thin
        ws1[f"B{idx}"].alignment = align_right
        ws1[f"C{idx}"].alignment = align_right

    ws1["A13"] = "Total Settlements"
    ws1["B13"] = "=SUM(B11:B12)"
    ws1["C13"] = "=SUM(C11:C12)"
    for c in ["A", "B", "C"]:
        ws1[f"{c}13"].font = font_total
        ws1[f"{c}13"].fill = fill_parchment
        ws1[f"{c}13"].border = border_total
    ws1["B13"].number_format = FMT_INTEGER
    ws1["C13"].number_format = FMT_CURRENCY
    ws1["B13"].alignment = align_right
    ws1["C13"].alignment = align_right

    # Revenue Classification Details
    for c, h in [("E", "Revenue Classification"), ("F", "Status / Notes"), ("G", "Amount (₱)")]:
        cell = ws1[f"{c}10"]
        cell.value = h
        cell.font = font_tbl_hdr
        cell.fill = fill_espresso
        cell.alignment = align_left if c == "E" else (align_center if c == "F" else align_right)
        cell.border = border_thin

    tax_rows = [
        ("Gross Sales (Subtotal)", "Standard Menu Sales", total_subtotal_gross),
        ("Less: Discounts Given", "20% SC/PWD & Promos", total_discounts),
        ("Net Realized Sales", "Actual Revenue Collected", total_sales),
        ("Sales Tax (VAT)", "0% Non-VAT Operational Model", 0.00),
    ]
    for idx, (t_cat, t_st, t_val) in enumerate(tax_rows, start=11):
        ws1[f"E{idx}"] = t_cat
        ws1[f"F{idx}"] = t_st
        ws1[f"G{idx}"] = t_val
        ws1[f"E{idx}"].font = font_main
        ws1[f"F{idx}"].font = Font(name="Segoe UI", size=8.5, color=COLOR_MUTED)
        ws1[f"G{idx}"].font = font_bold
        ws1[f"G{idx}"].number_format = FMT_CURRENCY
        ws1[f"E{idx}"].border = border_thin
        ws1[f"F{idx}"].border = border_thin
        ws1[f"G{idx}"].border = border_thin
        ws1[f"F{idx}"].alignment = align_center
        ws1[f"G{idx}"].alignment = align_right

    ws1["E15"] = "Net Certified Revenue"
    ws1["F15"] = "Formula: Gross - Discounts"
    ws1["G15"] = "=G11-G12"
    for c in ["E", "F", "G"]:
        ws1[f"{c}15"].font = font_total
        ws1[f"{c}15"].fill = fill_parchment
        ws1[f"{c}15"].border = border_total
    ws1["G15"].number_format = FMT_CURRENCY
    ws1["F15"].alignment = align_center
    ws1["G15"].alignment = align_right

    ws1.row_dimensions[16].height = 16

    # Product Category Breakdown (Rows 18+)
    ws1["A18"] = "📊 PRODUCT CATEGORY PERFORMANCE MIX"
    ws1["A18"].font = font_sec_hdr
    ws1.merge_cells("A18:D18")

    cat_headers = [("A", "Category Name"), ("B", "Units Sold"), ("C", "Revenue (₱)"), ("D", "% Revenue Share")]
    for c, h in cat_headers:
        cell = ws1[f"{c}19"]
        cell.value = h
        cell.font = font_tbl_hdr
        cell.fill = fill_caramel
        cell.alignment = align_left if c == "A" else align_right
        cell.border = border_thin

    sorted_cats = sorted(category_mix.items(), key=lambda x: x[1]['sales'], reverse=True)
    c_row = 20
    for cat_name, c_data in sorted_cats:
        ws1[f"A{c_row}"] = cat_name
        ws1[f"B{c_row}"] = c_data['qty']
        ws1[f"C{c_row}"] = c_data['sales']
        ws1[f"D{c_row}"] = f"=C{c_row}/$C${20 + len(sorted_cats)}"

        ws1[f"A{c_row}"].font = font_main
        ws1[f"B{c_row}"].font = font_main
        ws1[f"C{c_row}"].font = font_bold
        ws1[f"D{c_row}"].font = font_main

        ws1[f"B{c_row}"].number_format = FMT_INTEGER
        ws1[f"C{c_row}"].number_format = FMT_CURRENCY
        ws1[f"D{c_row}"].number_format = FMT_PERCENT

        for c in ["A", "B", "C", "D"]:
            cell = ws1[f"{c}{c_row}"]
            cell.border = border_thin
            if c_row % 2 == 1:
                cell.fill = fill_cream

        ws1[f"B{c_row}"].alignment = align_right
        ws1[f"C{c_row}"].alignment = align_right
        ws1[f"D{c_row}"].alignment = align_right
        c_row += 1

    ws1[f"A{c_row}"] = "Total Product Mix"
    ws1[f"B{c_row}"] = f"=SUM(B20:B{c_row-1})" if c_row > 20 else 0
    ws1[f"C{c_row}"] = f"=SUM(C20:C{c_row-1})" if c_row > 20 else 0.0
    ws1[f"D{c_row}"] = f"=SUM(D20:D{c_row-1})" if c_row > 20 else 0.0
    for c in ["A", "B", "C", "D"]:
        cell = ws1[f"{c}{c_row}"]
        cell.font = font_total
        cell.fill = fill_parchment
        cell.border = border_total
    ws1[f"B{c_row}"].number_format = FMT_INTEGER
    ws1[f"C{c_row}"].number_format = FMT_CURRENCY
    ws1[f"D{c_row}"].number_format = FMT_PERCENT
    ws1[f"B{c_row}"].alignment = align_right
    ws1[f"C{c_row}"].alignment = align_right
    ws1[f"D{c_row}"].alignment = align_right

    # Add Doughnut Chart for Category Sales Mix
    if len(sorted_cats) > 0:
        chart1 = DoughnutChart()
        chart1.title = "Sales Share by Product Category"
        chart1.style = 10
        chart1.width = 15
        chart1.height = 10
        chart1.legend.legendPos = "r"
        data_ref = Reference(ws1, min_col=3, min_row=19, max_row=c_row - 1)
        cats_ref = Reference(ws1, min_col=1, min_row=20, max_row=c_row - 1)
        chart1.add_data(data_ref, titles_from_data=True)
        chart1.set_categories(cats_ref)
        ws1.add_chart(chart1, "E18")

    ws1.column_dimensions['A'].width = 26
    ws1.column_dimensions['B'].width = 15
    ws1.column_dimensions['C'].width = 20
    ws1.column_dimensions['D'].width = 16
    ws1.column_dimensions['E'].width = 24
    ws1.column_dimensions['F'].width = 22
    ws1.column_dimensions['G'].width = 22

    # =========================================================================
    # SHEET 2: 🧾 Itemized Sales Ledger
    # =========================================================================
    ws2 = wb.create_sheet(title="Itemized Sales Ledger")
    ws2.sheet_properties.tabColor = COLOR_CARAMEL
    setup_page(ws2, orientation='landscape', title_rows='4:4')

    ws2.merge_cells("A1:U1")
    ws2.merge_cells("A2:U2")
    ws2["A1"] = "DK CAFE · ITEMIZED TRANSACTION AUDIT & SALES LEDGER"
    ws2["A1"].font = font_title
    ws2["A1"].fill = fill_espresso
    ws2["A1"].alignment = align_center
    ws2.row_dimensions[1].height = 32

    ws2["A2"] = f"Reporting Period: {period_title} | Complete Line-Item Register | Exported {now_str}"
    ws2["A2"].font = font_subtitle
    ws2["A2"].fill = fill_espresso
    ws2["A2"].alignment = align_center
    ws2.row_dimensions[2].height = 18
    ws2.row_dimensions[3].height = 8

    ledger_headers = [
        "Receipt #", "Date & Time", "Cashier", "Order Status", "Payment Method",
        "GCash Ref #", "Discount Type", "Senior/PWD ID", "Discount Reason",
        "Product Item", "Category", "Unit Price", "Qty", "Line Total",
        "Order Subtotal", "Order Discount", "Order Net Total", "Amount Paid",
        "Change Due", "Voided / Cancelled By", "Audit Reason / Notes"
    ]

    ws2.row_dimensions[4].height = 26
    for col_idx, h_text in enumerate(ledger_headers, start=1):
        col_letter = get_column_letter(col_idx)
        cell = ws2[f"{col_letter}4"]
        cell.value = h_text
        cell.font = font_tbl_hdr
        cell.fill = fill_espresso
        cell.border = border_thin
        cell.alignment = align_center

    fill_completed = PatternFill(start_color=STATUS_COMPLETED_BG, end_color=STATUS_COMPLETED_BG, fill_type="solid")
    font_completed = Font(name="Segoe UI", size=9, bold=True, color=STATUS_COMPLETED_FG)
    fill_pending   = PatternFill(start_color=STATUS_PENDING_BG, end_color=STATUS_PENDING_BG, fill_type="solid")
    font_pending   = Font(name="Segoe UI", size=9, bold=True, color=STATUS_PENDING_FG)
    fill_voided    = PatternFill(start_color=STATUS_VOIDED_BG, end_color=STATUS_VOIDED_BG, fill_type="solid")
    font_voided    = Font(name="Segoe UI", size=9, bold=True, color=STATUS_VOIDED_FG)

    l_row = 5
    for r in tx_rows:
        tId = r['transaction_id']
        receipt_str = f"#{tId:05d}"
        dt_str = r['timestamp'].strftime("%Y-%m-%d %H:%M:%S") if hasattr(r['timestamp'], 'strftime') else str(r['timestamp'])
        cashier = r['cashier_name'] or r['cashier_user'] or f"Staff #{r['cashier_user_id']}"
        status = r['status']
        pay = r['payment_method']
        ref = r['reference_number'] or "-"
        disc_type = (r['discount_type'] or "NONE").upper()
        disc_id = r['discount_id_no'] or "-"
        disc_reason = r['discount_reason'] or "-"
        prod = r['product_name'] or r['current_name'] or f"Item #{r['product_id']}"
        cat = r['current_category'] or "General"
        unit_p = float(r['unit_price'] or r['current_price'] or 0)
        qty = int(r['quantity'] or 1)
        line_t = round(unit_p * qty, 2)
        subtotal = float(r['subtotal'] or r['total_amount'])
        discount = float(r['discount'] or 0)
        net_t = float(r['total_amount'] or 0)
        paid = float(r['amount_paid'] or net_t)
        change = float(r['change_due'] or 0)
        canceller = r['canceller_name'] or r['canceller_user'] or ("-" if not r['cancelled_by_user_id'] else f"Staff #{r['cancelled_by_user_id']}")
        reason = r['cancellation_reason'] or "-"

        row_vals = [
            receipt_str, dt_str, cashier, status, pay,
            ref, disc_type, disc_id, disc_reason,
            prod, cat, unit_p, qty, line_t,
            subtotal, discount, net_t, paid,
            change, canceller, reason
        ]

        ws2.row_dimensions[l_row].height = 20
        is_zebra = (l_row % 2 == 1)

        for col_idx, val in enumerate(row_vals, start=1):
            col_letter = get_column_letter(col_idx)
            cell = ws2[f"{col_letter}{l_row}"]
            cell.value = val
            cell.font = font_main
            cell.border = border_thin
            if is_zebra:
                cell.fill = fill_cream

            if col_idx in [1, 2, 5, 6, 7, 8]:
                cell.alignment = align_center
            elif col_idx == 4:
                cell.alignment = align_center
                if status == 'Completed':
                    cell.fill = fill_completed
                    cell.font = font_completed
                elif status == 'Pending':
                    cell.fill = fill_pending
                    cell.font = font_pending
                else:
                    cell.fill = fill_voided
                    cell.font = font_voided
            elif col_idx in [12, 14, 15, 16, 17, 18, 19]:
                cell.alignment = align_right
                cell.number_format = FMT_CURRENCY
            elif col_idx == 13:
                cell.alignment = align_right
                cell.number_format = FMT_INTEGER
            else:
                cell.alignment = align_left

        l_row += 1

    if l_row > 5:
        ws2.row_dimensions[l_row].height = 24
        ws2[f"A{l_row}"] = "TOTALS (SUBTOTAL)"
        ws2[f"A{l_row}"].font = font_total
        ws2[f"A{l_row}"].alignment = align_center

        ws2[f"M{l_row}"] = f"=SUBTOTAL(9, M5:M{l_row-1})"
        ws2[f"M{l_row}"].number_format = FMT_INTEGER
        ws2[f"M{l_row}"].font = font_total
        ws2[f"M{l_row}"].alignment = align_right

        ws2[f"N{l_row}"] = f"=SUBTOTAL(9, N5:N{l_row-1})"
        ws2[f"N{l_row}"].number_format = FMT_CURRENCY
        ws2[f"N{l_row}"].font = font_total
        ws2[f"N{l_row}"].alignment = align_right

        ws2[f"P{l_row}"] = f"=SUBTOTAL(9, P5:P{l_row-1})"
        ws2[f"P{l_row}"].number_format = FMT_CURRENCY
        ws2[f"P{l_row}"].font = font_total
        ws2[f"P{l_row}"].alignment = align_right

        ws2[f"Q{l_row}"] = f"=SUBTOTAL(9, Q5:Q{l_row-1})"
        ws2[f"Q{l_row}"].number_format = FMT_CURRENCY
        ws2[f"Q{l_row}"].font = font_total
        ws2[f"Q{l_row}"].alignment = align_right

        for c_i in range(1, 22):
            c_l = get_column_letter(c_i)
            ws2[f"{c_l}{l_row}"].fill = fill_parchment
            ws2[f"{c_l}{l_row}"].border = border_total

        ws2.auto_filter.ref = f"A4:U{l_row-1}"

    ws2.freeze_panes = "A5"
    auto_fit_columns(ws2, min_col=1, max_col=21, start_row=4, min_width=12, padding=3, custom_widths={
        'A': 13, 'B': 20, 'C': 18, 'D': 15, 'E': 14, 'F': 16,
        'G': 15, 'H': 16, 'I': 22, 'J': 28, 'K': 18, 'L': 14,
        'M': 10, 'N': 16, 'O': 16, 'P': 16, 'Q': 18, 'R': 16,
        'S': 14, 'T': 18, 'U': 24
    })

    # =========================================================================
    # SHEET 3: 🏆 Product Performance
    # =========================================================================
    ws3 = wb.create_sheet(title="Product Performance")
    ws3.sheet_properties.tabColor = COLOR_FOREST
    setup_page(ws3, orientation='portrait', title_rows='4:4')

    ws3.merge_cells("A1:G1")
    ws3.merge_cells("A2:G2")
    ws3["A1"] = "DK CAFE · PRODUCT SALES MIX & POPULARITY RANKING"
    ws3["A1"].font = font_title
    ws3["A1"].fill = fill_espresso
    ws3["A1"].alignment = align_center
    ws3.row_dimensions[1].height = 32

    ws3["A2"] = f"Unit volumes, total revenues, and revenue contribution percentages | {period_title}"
    ws3["A2"].font = font_subtitle
    ws3["A2"].fill = fill_espresso
    ws3["A2"].alignment = align_center
    ws3.row_dimensions[2].height = 18
    ws3.row_dimensions[3].height = 8

    prod_headers = [
        ("A", "Rank"),
        ("B", "Menu Item Name"),
        ("C", "Category"),
        ("D", "Base Price"),
        ("E", "Units Sold"),
        ("F", "Total Sales (₱)"),
        ("G", "% Revenue Contribution")
    ]

    ws3.row_dimensions[4].height = 26
    for c, h in prod_headers:
        cell = ws3[f"{c}4"]
        cell.value = h
        cell.font = font_tbl_hdr
        cell.fill = fill_espresso
        cell.border = border_thin
        cell.alignment = align_center if c == "A" else (align_left if c in ["B", "C"] else align_right)

    sorted_prods = sorted(product_mix.items(), key=lambda x: x[1]['sales'], reverse=True)
    p_row = 5
    for rank, (p_name, p_data) in enumerate(sorted_prods, start=1):
        rank_label = f"#{rank}"
        if rank == 1:
            rank_label = "🥇 #1"
        elif rank == 2:
            rank_label = "🥈 #2"
        elif rank == 3:
            rank_label = "🥉 #3"

        ws3[f"A{p_row}"] = rank_label
        ws3[f"B{p_row}"] = p_name
        ws3[f"C{p_row}"] = p_data['cat']
        ws3[f"D{p_row}"] = p_data['price']
        ws3[f"E{p_row}"] = p_data['qty']
        ws3[f"F{p_row}"] = p_data['sales']
        ws3[f"G{p_row}"] = f"=F{p_row}/$F${5 + len(sorted_prods)}"

        ws3[f"A{p_row}"].font = font_bold if rank <= 3 else font_main
        ws3[f"B{p_row}"].font = font_bold if rank <= 3 else font_main
        ws3[f"C{p_row}"].font = font_main
        ws3[f"D{p_row}"].font = font_main
        ws3[f"E{p_row}"].font = font_bold
        ws3[f"F{p_row}"].font = font_bold
        ws3[f"G{p_row}"].font = font_main

        ws3[f"D{p_row}"].number_format = FMT_CURRENCY
        ws3[f"E{p_row}"].number_format = FMT_INTEGER
        ws3[f"F{p_row}"].number_format = FMT_CURRENCY
        ws3[f"G{p_row}"].number_format = FMT_PERCENT

        is_zebra = (p_row % 2 == 1)
        for c in ["A", "B", "C", "D", "E", "F", "G"]:
            cell = ws3[f"{c}{p_row}"]
            cell.border = border_thin
            if is_zebra:
                cell.fill = fill_cream

        ws3[f"A{p_row}"].alignment = align_center
        ws3[f"B{p_row}"].alignment = align_left
        ws3[f"C{p_row}"].alignment = align_left
        ws3[f"D{p_row}"].alignment = align_right
        ws3[f"E{p_row}"].alignment = align_right
        ws3[f"F{p_row}"].alignment = align_right
        ws3[f"G{p_row}"].alignment = align_right
        p_row += 1

    if p_row > 5:
        ws3[f"A{p_row}"] = "TOTALS"
        ws3[f"B{p_row}"] = f"{len(sorted_prods)} Products Active"
        ws3[f"E{p_row}"] = f"=SUM(E5:E{p_row-1})"
        ws3[f"F{p_row}"] = f"=SUM(F5:F{p_row-1})"
        ws3[f"G{p_row}"] = f"=SUM(G5:G{p_row-1})"

        for c in ["A", "B", "C", "D", "E", "F", "G"]:
            cell = ws3[f"{c}{p_row}"]
            cell.font = font_total
            cell.fill = fill_parchment
            cell.border = border_total

        ws3[f"E{p_row}"].number_format = FMT_INTEGER
        ws3[f"F{p_row}"].number_format = FMT_CURRENCY
        ws3[f"G{p_row}"].number_format = FMT_PERCENT
        ws3[f"E{p_row}"].alignment = align_right
        ws3[f"F{p_row}"].alignment = align_right
        ws3[f"G{p_row}"].alignment = align_right

        ws3.auto_filter.ref = f"A4:G{p_row-1}"

    # Add Top 10 Best Sellers Bar Chart
    if len(sorted_prods) > 0:
        chart2 = BarChart()
        chart2.type = "bar"  # Horizontal bar chart
        chart2.style = 10
        chart2.title = "Top Best Selling Products by Revenue"
        chart2.y_axis.title = "Menu Item"
        chart2.x_axis.title = "Revenue (₱)"
        chart2.width = 17
        chart2.height = 12
        chart2.legend = None

        top_limit = min(10, len(sorted_prods))
        data_ref = Reference(ws3, min_col=6, min_row=4, max_row=4 + top_limit)
        cats_ref = Reference(ws3, min_col=2, min_row=5, max_row=4 + top_limit)
        chart2.add_data(data_ref, titles_from_data=True)
        chart2.set_categories(cats_ref)
        ws3.add_chart(chart2, "I4")

    ws3.freeze_panes = "A5"
    auto_fit_columns(ws3, min_col=1, max_col=7, start_row=4, min_width=12, padding=3, custom_widths={
        'A': 10, 'B': 32, 'C': 20, 'D': 16, 'E': 14, 'F': 20, 'G': 22
    })

    # =========================================================================
    # SHEET 4: 📦 Raw Supplies Inventory & Stock Health
    # =========================================================================
    ws4 = wb.create_sheet(title="Raw Inventory & Stock Health")
    ws4.sheet_properties.tabColor = "2563EB"  # Deep blue tab
    setup_page(ws4, orientation='portrait', title_rows='8:8')

    ws4.merge_cells("A1:H1")
    ws4.merge_cells("A2:H2")
    ws4["A1"] = "DK CAFE · RAW SUPPLIES INVENTORY & REORDER AUDIT"
    ws4["A1"].font = font_title
    ws4["A1"].fill = fill_espresso
    ws4["A1"].alignment = align_center
    ws4.row_dimensions[1].height = 32

    ws4["A2"] = f"Current Physical Stock Levels, Restock Thresholds & Alert Status | As of {now_str}"
    ws4["A2"].font = font_subtitle
    ws4["A2"].fill = fill_espresso
    ws4["A2"].alignment = align_center
    ws4.row_dimensions[2].height = 18
    ws4.row_dimensions[3].height = 10

    # Inventory Metrics
    total_supplies = len(inv_rows)
    out_of_stock_cnt = sum(1 for item in inv_rows if float(item['current_stock'] or 0) <= 0)
    low_stock_cnt = sum(1 for item in inv_rows if 0 < float(item['current_stock'] or 0) <= float(item['restock_threshold'] or 0))
    healthy_cnt = sum(1 for item in inv_rows if float(item['current_stock'] or 0) > float(item['restock_threshold'] or 0))

    inv_kpis = [
        ("A", "B", "TOTAL SUPPLIES TRACKED", total_supplies, FMT_INTEGER, "Active & inactive items"),
        ("C", "D", "HEALTHY STOCK (ADEQUATE)", healthy_cnt, FMT_INTEGER, "Stock above reorder point"),
        ("E", "F", "LOW STOCK (REORDER NEEDED)", low_stock_cnt, FMT_INTEGER, "At or below alert threshold"),
        ("G", "H", "OUT OF STOCK / CRITICAL", out_of_stock_cnt, FMT_INTEGER, "Zero or depleted stock")
    ]

    ws4.row_dimensions[4].height = 16
    ws4.row_dimensions[5].height = 30
    ws4.row_dimensions[6].height = 16

    for c_start, c_end, lbl, val, fmt, sub in inv_kpis:
        ws4.merge_cells(f"{c_start}4:{c_end}4")
        ws4.merge_cells(f"{c_start}5:{c_end}5")
        ws4.merge_cells(f"{c_start}6:{c_end}6")

        ws4[f"{c_start}4"] = lbl
        ws4[f"{c_start}4"].font = font_kpi_lbl
        ws4[f"{c_start}4"].alignment = align_center

        ws4[f"{c_start}5"] = val
        ws4[f"{c_start}5"].font = font_kpi_num
        ws4[f"{c_start}5"].number_format = fmt
        ws4[f"{c_start}5"].alignment = align_center

        ws4[f"{c_start}6"] = sub
        ws4[f"{c_start}6"].font = Font(name="Segoe UI", size=7.5, italic=True, color=COLOR_MUTED)
        ws4[f"{c_start}6"].alignment = align_center

        cols_range = [c_start, c_end]
        for c in cols_range:
            for r_idx in [4, 5, 6]:
                cell = ws4[f"{c}{r_idx}"]
                cell.fill = fill_card
                cell.border = border_card

    ws4.row_dimensions[7].height = 14

    inv_headers = [
        ("A", "Item ID"),
        ("B", "Supply Name"),
        ("C", "Current Stock"),
        ("D", "Unit of Measure"),
        ("E", "Alert Threshold"),
        ("F", "Stock Health Status"),
        ("G", "Operational Status"),
        ("H", "Reorder Recommendation")
    ]

    ws4.row_dimensions[8].height = 26
    for c, h in inv_headers:
        cell = ws4[f"{c}8"]
        cell.value = h
        cell.font = font_tbl_hdr
        cell.fill = fill_espresso
        cell.border = border_thin
        cell.alignment = align_center if c in ["A", "D", "F", "G"] else (align_right if c in ["C", "E"] else align_left)

    fill_ok  = PatternFill(start_color=STOCK_OK_BG, end_color=STOCK_OK_BG, fill_type="solid")
    font_ok  = Font(name="Segoe UI", size=9, bold=True, color=STOCK_OK_FG)
    fill_low = PatternFill(start_color=STOCK_LOW_BG, end_color=STOCK_LOW_BG, fill_type="solid")
    font_low = Font(name="Segoe UI", size=9, bold=True, color=STOCK_LOW_FG)
    fill_out = PatternFill(start_color=STOCK_OUT_BG, end_color=STOCK_OUT_BG, fill_type="solid")
    font_out = Font(name="Segoe UI", size=9, bold=True, color=STOCK_OUT_FG)

    i_row = 9
    for item in inv_rows:
        i_id = item['item_id']
        name = item['name']
        stock = float(item['current_stock'] or 0)
        unit = item['unit_measure']
        threshold = float(item['restock_threshold'] or 0)
        is_active = bool(item['is_active'])

        if stock <= 0:
            status_text = "OUT OF STOCK"
            status_fill = fill_out
            status_font = font_out
            reorder_text = f"Replenish urgently (+{threshold:,.0f} {unit})"
        elif stock <= threshold:
            status_text = "LOW STOCK (REORDER)"
            status_fill = fill_low
            status_font = font_low
            deficit = max(0, (threshold * 2) - stock)
            reorder_text = f"Reorder recommended (+{deficit:,.0f} {unit})"
        else:
            status_text = "HEALTHY"
            status_fill = fill_ok
            status_font = font_ok
            reorder_text = "Stock is adequate"

        active_text = "Active" if is_active else "Inactive"

        ws4[f"A{i_row}"] = f"#{i_id:02d}"
        ws4[f"B{i_row}"] = name
        ws4[f"C{i_row}"] = stock
        ws4[f"D{i_row}"] = unit
        ws4[f"E{i_row}"] = threshold
        ws4[f"F{i_row}"] = status_text
        ws4[f"G{i_row}"] = active_text
        ws4[f"H{i_row}"] = reorder_text

        for c in ["A", "B", "C", "D", "E", "F", "G", "H"]:
            cell = ws4[f"{c}{i_row}"]
            cell.font = font_main
            cell.border = border_thin
            if i_row % 2 == 1:
                cell.fill = fill_cream

        ws4[f"C{i_row}"].number_format = FMT_DECIMAL
        ws4[f"E{i_row}"].number_format = FMT_DECIMAL

        ws4[f"A{i_row}"].alignment = align_center
        ws4[f"B{i_row}"].alignment = align_left
        ws4[f"C{i_row}"].alignment = align_right
        ws4[f"D{i_row}"].alignment = align_center
        ws4[f"E{i_row}"].alignment = align_right
        ws4[f"G{i_row}"].alignment = align_center
        ws4[f"H{i_row}"].alignment = align_left

        # Pill styling on status
        ws4[f"F{i_row}"].fill = status_fill
        ws4[f"F{i_row}"].font = status_font
        ws4[f"F{i_row}"].alignment = align_center

        ws4.row_dimensions[i_row].height = 20
        i_row += 1

    if i_row > 9:
        ws4.auto_filter.ref = f"A8:H{i_row-1}"

    ws4.freeze_panes = "A9"
    auto_fit_columns(ws4, min_col=1, max_col=8, start_row=8, min_width=12, padding=3, custom_widths={
        'A': 10, 'B': 30, 'C': 16, 'D': 16, 'E': 16, 'F': 22, 'G': 18, 'H': 32
    })

    # Save final workbook
    os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
    wb.save(output_path)
    print(f"SUCCESS: Exported {output_path} ({os.path.getsize(output_path):,} bytes)")

def generate_inventory_workbook(output_path):
    """Generates a dedicated standalone Raw Supplies & Recipe Ingredients workbook."""
    conn = get_db_connection()
    with conn.cursor() as cur:
        cur.execute("""
            SELECT item_id, name, current_stock, unit_measure, restock_threshold, is_active
              FROM inventory
             ORDER BY is_active DESC, (current_stock <= restock_threshold) DESC, name ASC
        """)
        inv_rows = cur.fetchall()

        cur.execute("""
            SELECT pi.id, pi.product_id, pi.item_id, pi.deduction_amount,
                   mp.name AS product_name, mp.category, mp.price AS product_price,
                   inv.name AS supply_name, inv.unit_measure
              FROM product_ingredients pi
              JOIN menu_products mp ON mp.product_id = pi.product_id
              JOIN inventory inv ON inv.item_id = pi.item_id
             ORDER BY mp.category ASC, mp.name ASC, inv.name ASC
        """)
        recipe_rows = cur.fetchall()

    wb = openpyxl.Workbook()

    font_main     = Font(name="Segoe UI", size=9.5, color=COLOR_INK)
    font_bold     = Font(name="Segoe UI", size=9.5, bold=True, color=COLOR_INK)
    font_title    = Font(name="Segoe UI", size=15, bold=True, color=COLOR_WHITE)
    font_subtitle = Font(name="Segoe UI", size=9, italic=True, color="EBE3D5")
    font_tbl_hdr  = Font(name="Segoe UI", size=10, bold=True, color=COLOR_WHITE)
    font_kpi_num  = Font(name="Segoe UI", size=17, bold=True, color=COLOR_ESPRESSO)
    font_kpi_lbl  = Font(name="Segoe UI", size=8.5, bold=True, color=COLOR_MUTED)

    fill_espresso  = PatternFill(start_color=COLOR_ESPRESSO, end_color=COLOR_ESPRESSO, fill_type="solid")
    fill_caramel   = PatternFill(start_color=COLOR_CARAMEL, end_color=COLOR_CARAMEL, fill_type="solid")
    fill_cream     = PatternFill(start_color=COLOR_CREAM, end_color=COLOR_CREAM, fill_type="solid")
    fill_card      = PatternFill(start_color=COLOR_CARD_BG, end_color=COLOR_CARD_BG, fill_type="solid")

    border_thin = Border(
        left=Side(style='thin', color=COLOR_BORDER),
        right=Side(style='thin', color=COLOR_BORDER),
        top=Side(style='thin', color=COLOR_BORDER),
        bottom=Side(style='thin', color=COLOR_BORDER)
    )
    border_card = Border(
        left=Side(style='thin', color=COLOR_CARD_BORDER),
        right=Side(style='thin', color=COLOR_CARD_BORDER),
        top=Side(style='thin', color=COLOR_CARD_BORDER),
        bottom=Side(style='thin', color=COLOR_CARD_BORDER)
    )

    align_left   = Alignment(horizontal='left', vertical='center')
    align_center = Alignment(horizontal='center', vertical='center')
    align_right  = Alignment(horizontal='right', vertical='center')

    now_str = datetime.datetime.now().strftime("%B %d, %Y at %I:%M %p")

    # Sheet 1: Raw Supplies Inventory
    ws1 = wb.active
    ws1.title = "Raw Supplies Inventory"
    ws1.sheet_properties.tabColor = COLOR_ESPRESSO
    setup_page(ws1, orientation='portrait', title_rows='8:8')

    ws1.merge_cells("A1:H1")
    ws1.merge_cells("A2:H2")
    ws1["A1"] = "DK CAFE · RAW SUPPLIES INVENTORY & REORDER AUDIT"
    ws1["A1"].font = font_title
    ws1["A1"].fill = fill_espresso
    ws1["A1"].alignment = align_center
    ws1.row_dimensions[1].height = 32

    ws1["A2"] = f"Physical Supplies Balance, Alert Thresholds & Replenishment Directives | Generated: {now_str}"
    ws1["A2"].font = font_subtitle
    ws1["A2"].fill = fill_espresso
    ws1["A2"].alignment = align_center
    ws1.row_dimensions[2].height = 18
    ws1.row_dimensions[3].height = 10

    total_supplies = len(inv_rows)
    out_of_stock_cnt = sum(1 for item in inv_rows if float(item['current_stock'] or 0) <= 0)
    low_stock_cnt = sum(1 for item in inv_rows if 0 < float(item['current_stock'] or 0) <= float(item['restock_threshold'] or 0))
    healthy_cnt = sum(1 for item in inv_rows if float(item['current_stock'] or 0) > float(item['restock_threshold'] or 0))

    inv_kpis = [
        ("A", "B", "TOTAL SUPPLIES TRACKED", total_supplies, FMT_INTEGER, "Active & inactive inventory"),
        ("C", "D", "HEALTHY STOCK (ADEQUATE)", healthy_cnt, FMT_INTEGER, "Stock above reorder point"),
        ("E", "F", "LOW STOCK (REORDER NEEDED)", low_stock_cnt, FMT_INTEGER, "At or below alert threshold"),
        ("G", "H", "OUT OF STOCK / CRITICAL", out_of_stock_cnt, FMT_INTEGER, "Zero or depleted stock")
    ]

    ws1.row_dimensions[4].height = 16
    ws1.row_dimensions[5].height = 30
    ws1.row_dimensions[6].height = 16

    for c_start, c_end, lbl, val, fmt, sub in inv_kpis:
        ws1.merge_cells(f"{c_start}4:{c_end}4")
        ws1.merge_cells(f"{c_start}5:{c_end}5")
        ws1.merge_cells(f"{c_start}6:{c_end}6")

        ws1[f"{c_start}4"] = lbl
        ws1[f"{c_start}4"].font = font_kpi_lbl
        ws1[f"{c_start}4"].alignment = align_center

        ws1[f"{c_start}5"] = val
        ws1[f"{c_start}5"].font = font_kpi_num
        ws1[f"{c_start}5"].number_format = fmt
        ws1[f"{c_start}5"].alignment = align_center

        ws1[f"{c_start}6"] = sub
        ws1[f"{c_start}6"].font = Font(name="Segoe UI", size=7.5, italic=True, color=COLOR_MUTED)
        ws1[f"{c_start}6"].alignment = align_center

        for c in [c_start, c_end]:
            for r_idx in [4, 5, 6]:
                cell = ws1[f"{c}{r_idx}"]
                cell.fill = fill_card
                cell.border = border_card

    ws1.row_dimensions[7].height = 14

    inv_headers = [
        ("A", "Item ID"),
        ("B", "Supply Name"),
        ("C", "Current Stock"),
        ("D", "Unit of Measure"),
        ("E", "Alert Threshold"),
        ("F", "Stock Health Status"),
        ("G", "Operational Status"),
        ("H", "Reorder Recommendation")
    ]

    ws1.row_dimensions[8].height = 26
    for c, h in inv_headers:
        cell = ws1[f"{c}8"]
        cell.value = h
        cell.font = font_tbl_hdr
        cell.fill = fill_espresso
        cell.border = border_thin
        cell.alignment = align_center if c in ["A", "D", "F", "G"] else (align_right if c in ["C", "E"] else align_left)

    fill_ok  = PatternFill(start_color=STOCK_OK_BG, end_color=STOCK_OK_BG, fill_type="solid")
    font_ok  = Font(name="Segoe UI", size=9, bold=True, color=STOCK_OK_FG)
    fill_low = PatternFill(start_color=STOCK_LOW_BG, end_color=STOCK_LOW_BG, fill_type="solid")
    font_low = Font(name="Segoe UI", size=9, bold=True, color=STOCK_LOW_FG)
    fill_out = PatternFill(start_color=STOCK_OUT_BG, end_color=STOCK_OUT_BG, fill_type="solid")
    font_out = Font(name="Segoe UI", size=9, bold=True, color=STOCK_OUT_FG)

    i_row = 9
    for item in inv_rows:
        i_id = item['item_id']
        name = item['name']
        stock = float(item['current_stock'] or 0)
        unit = item['unit_measure']
        threshold = float(item['restock_threshold'] or 0)
        is_active = bool(item['is_active'])

        if stock <= 0:
            status_text = "OUT OF STOCK"
            status_fill = fill_out
            status_font = font_out
            reorder_text = f"Replenish urgently (+{threshold:,.0f} {unit})"
        elif stock <= threshold:
            status_text = "LOW STOCK (REORDER)"
            status_fill = fill_low
            status_font = font_low
            deficit = max(0, (threshold * 2) - stock)
            reorder_text = f"Reorder recommended (+{deficit:,.0f} {unit})"
        else:
            status_text = "HEALTHY"
            status_fill = fill_ok
            status_font = font_ok
            reorder_text = "Stock is adequate"

        active_text = "Active" if is_active else "Inactive"

        ws1[f"A{i_row}"] = f"#{i_id:02d}"
        ws1[f"B{i_row}"] = name
        ws1[f"C{i_row}"] = stock
        ws1[f"D{i_row}"] = unit
        ws1[f"E{i_row}"] = threshold
        ws1[f"F{i_row}"] = status_text
        ws1[f"G{i_row}"] = active_text
        ws1[f"H{i_row}"] = reorder_text

        for c in ["A", "B", "C", "D", "E", "F", "G", "H"]:
            cell = ws1[f"{c}{i_row}"]
            cell.font = font_main
            cell.border = border_thin
            if i_row % 2 == 1:
                cell.fill = fill_cream

        ws1[f"C{i_row}"].number_format = FMT_DECIMAL
        ws1[f"E{i_row}"].number_format = FMT_DECIMAL

        ws1[f"A{i_row}"].alignment = align_center
        ws1[f"B{i_row}"].alignment = align_left
        ws1[f"C{i_row}"].alignment = align_right
        ws1[f"D{i_row}"].alignment = align_center
        ws1[f"E{i_row}"].alignment = align_right
        ws1[f"G{i_row}"].alignment = align_center
        ws1[f"H{i_row}"].alignment = align_left

        ws1[f"F{i_row}"].fill = status_fill
        ws1[f"F{i_row}"].font = status_font
        ws1[f"F{i_row}"].alignment = align_center

        ws1.row_dimensions[i_row].height = 20
        i_row += 1

    if i_row > 9:
        ws1.auto_filter.ref = f"A8:H{i_row-1}"

    ws1.freeze_panes = "A9"
    auto_fit_columns(ws1, min_col=1, max_col=8, start_row=8, min_width=12, padding=3, custom_widths={
        'A': 10, 'B': 30, 'C': 16, 'D': 16, 'E': 16, 'F': 22, 'G': 18, 'H': 32
    })

    # Sheet 2: Menu Recipes & Deductions
    ws2 = wb.create_sheet(title="Menu Recipe Mapping")
    ws2.sheet_properties.tabColor = COLOR_CARAMEL
    setup_page(ws2, orientation='portrait', title_rows='4:4')

    ws2.merge_cells("A1:G1")
    ws2.merge_cells("A2:G2")
    ws2["A1"] = "DK CAFE · MENU PRODUCT INGREDIENT RECIPES & DEDUCTIONS"
    ws2["A1"].font = font_title
    ws2["A1"].fill = fill_espresso
    ws2["A1"].alignment = align_center
    ws2.row_dimensions[1].height = 32

    ws2["A2"] = f"Automatic inventory consumption formula per menu item | {len(recipe_rows)} Recipe Bindings"
    ws2["A2"].font = font_subtitle
    ws2["A2"].fill = fill_espresso
    ws2["A2"].alignment = align_center
    ws2.row_dimensions[2].height = 18
    ws2.row_dimensions[3].height = 8

    rec_headers = [
        ("A", "Product ID"),
        ("B", "Menu Product"),
        ("C", "Category"),
        ("D", "Menu Price"),
        ("E", "Ingredient Supply"),
        ("F", "Deduction Amount"),
        ("G", "Unit of Measure")
    ]

    ws2.row_dimensions[4].height = 26
    for c, h in rec_headers:
        cell = ws2[f"{c}4"]
        cell.value = h
        cell.font = font_tbl_hdr
        cell.fill = fill_espresso
        cell.border = border_thin
        cell.alignment = align_center if c in ["A", "G"] else (align_right if c in ["D", "F"] else align_left)

    r_row = 5
    for r in recipe_rows:
        ws2[f"A{r_row}"] = f"#{r['product_id']:02d}"
        ws2[f"B{r_row}"] = r['product_name']
        ws2[f"C{r_row}"] = r['category']
        ws2[f"D{r_row}"] = float(r['product_price'] or 0)
        ws2[f"E{r_row}"] = r['supply_name']
        ws2[f"F{r_row}"] = float(r['deduction_amount'] or 0)
        ws2[f"G{r_row}"] = r['unit_measure']

        for c in ["A", "B", "C", "D", "E", "F", "G"]:
            cell = ws2[f"{c}{r_row}"]
            cell.font = font_main
            cell.border = border_thin
            if r_row % 2 == 1:
                cell.fill = fill_cream

        ws2[f"D{r_row}"].number_format = FMT_CURRENCY
        ws2[f"F{r_row}"].number_format = FMT_DECIMAL

        ws2[f"A{r_row}"].alignment = align_center
        ws2[f"B{r_row}"].alignment = align_left
        ws2[f"C{r_row}"].alignment = align_left
        ws2[f"D{r_row}"].alignment = align_right
        ws2[f"E{r_row}"].alignment = align_left
        ws2[f"F{r_row}"].alignment = align_right
        ws2[f"G{r_row}"].alignment = align_center

        ws2.row_dimensions[r_row].height = 20
        r_row += 1

    if r_row > 5:
        ws2.auto_filter.ref = f"A4:G{r_row-1}"

    ws2.freeze_panes = "A5"
    auto_fit_columns(ws2, min_col=1, max_col=7, start_row=4, min_width=12, padding=3, custom_widths={
        'A': 12, 'B': 30, 'C': 18, 'D': 16, 'E': 28, 'F': 18, 'G': 16
    })

    os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
    wb.save(output_path)
    print(f"SUCCESS: Exported {output_path} ({os.path.getsize(output_path):,} bytes)")

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description="CafeFlow POS Professional Excel Report Generator")
    parser.add_argument("--output", required=True, help="Path to save output .xlsx file")
    parser.add_argument("--start", help="Start date (YYYY-MM-DD)")
    parser.add_argument("--end", help="End date (YYYY-MM-DD)")
    parser.add_argument("--date", help="Single date (YYYY-MM-DD)")
    parser.add_argument("--all", action="store_true", help="Export all historical records")
    parser.add_argument("--type", default="sales", choices=["sales", "inventory"], help="Report type: sales or inventory")
    args = parser.parse_args()

    if args.type == "inventory":
        generate_inventory_workbook(args.output)
    else:
        generate_sales_workbook(
            output_path=args.output,
            start_date=args.start,
            end_date=args.end,
            single_date=args.date,
            is_all=args.all
        )
