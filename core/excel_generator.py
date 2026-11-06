# -*- coding: utf-8 -*-
"""
core/excel_generator.py - Enterprise-Grade Multi-Sheet Excel (.xlsx) Designer for CafeFlow POS.
Generates an executive-level dashboard, itemized audit ledger, product mix ranking, and cash/tax reconciliation.
"""

import sys, os, argparse, datetime
import pymysql
import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

def generate_workbook(output_path, start_date=None, end_date=None, single_date=None, is_all=False):
    # Connect to MySQL Database
    conn = pymysql.connect(
        host='127.0.0.1', user='root', password='', database='cafeflow',
        cursorclass=pymysql.cursors.DictCursor
    )

    where_clause = ""
    params = []
    period_title = ""

    if is_all:
        where_clause = "WHERE 1=1"
        period_title = "All Historical Transactions"
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
        # Default today
        today_str = datetime.date.today().strftime("%Y-%m-%d")
        where_clause = "WHERE t.timestamp >= %s AND t.timestamp < %s"
        params.append(f"{today_str} 00:00:00")
        end_dt = datetime.date.today() + datetime.timedelta(days=1)
        params.append(end_dt.strftime("%Y-%m-%d 00:00:00"))
        period_title = f"Today ({today_str})"

    sql = f"""
        SELECT t.transaction_id, t.total_amount, t.amount_paid, t.change_due,
               t.subtotal, t.tax, t.discount, t.discount_type, t.discount_rate,
               t.discount_id_no, t.discount_reason,
               t.status, t.payment_method, t.reference_number,
               t.cashier_user_id, u_c.full_name AS cashier_name, u_c.username AS cashier_user,
               t.cancelled_by_user_id, u_v.full_name AS canceller_name, u_v.username AS canceller_user,
               t.cancelled_at, t.cancellation_reason, t.timestamp,
               ti.id AS item_row_id, ti.product_id, ti.product_name, ti.quantity, ti.unit_price,
               mp.name AS current_name, mp.category AS current_category, mp.price AS current_price
          FROM transactions t
          JOIN transaction_items ti ON ti.transaction_id = t.transaction_id
          LEFT JOIN users u_c ON u_c.user_id = t.cashier_user_id
          LEFT JOIN users u_v ON u_v.user_id = t.cancelled_by_user_id
          LEFT JOIN menu_products mp ON mp.product_id = ti.product_id
         {where_clause}
         ORDER BY t.timestamp ASC, t.transaction_id ASC, ti.id ASC
    """

    with conn.cursor() as cur:
        cur.execute(sql, params)
        rows = cur.fetchall()

    wb = openpyxl.Workbook()

    # Color Palette Definitions (Modern Enterprise Coffee / Executive)
    COLOR_ESPRESSO = "2C1A0E"     # Deep primary dark
    COLOR_CARAMEL  = "C07C2E"     # Rich accent amber
    COLOR_PARCHMENT= "FBF7EE"     # Warm header / card background
    COLOR_CREAM    = "F9F5EC"     # Alternating zebra row
    COLOR_BORDER   = "E4D7C5"     # Subtle gridline border
    COLOR_WHITE    = "FFFFFF"
    COLOR_INK      = "1E1E1E"
    COLOR_MUTED    = "666666"
    COLOR_GREEN    = "1E7E34"     # Completed / Profit green
    COLOR_AMBER    = "D97706"     # Pending amber
    COLOR_RED      = "DC2626"     # Cancelled / Voided red
    COLOR_CARD_BG  = "F4ECE1"     # KPI Card background

    font_main = Font(name="Segoe UI", size=10, color=COLOR_INK)
    font_bold = Font(name="Segoe UI", size=10, bold=True, color=COLOR_INK)
    font_title = Font(name="Segoe UI", size=15, bold=True, color=COLOR_WHITE)
    font_subtitle = Font(name="Segoe UI", size=9, italic=True, color="E8DFD5")
    font_sec_hdr = Font(name="Segoe UI", size=11, bold=True, color=COLOR_ESPRESSO)
    font_tbl_hdr = Font(name="Segoe UI", size=10, bold=True, color=COLOR_WHITE)
    font_total = Font(name="Segoe UI", size=10, bold=True, color=COLOR_ESPRESSO)
    font_kpi_num = Font(name="Segoe UI", size=16, bold=True, color=COLOR_ESPRESSO)
    font_kpi_lbl = Font(name="Segoe UI", size=8, bold=True, color=COLOR_MUTED)

    fill_espresso = PatternFill(start_color=COLOR_ESPRESSO, end_color=COLOR_ESPRESSO, fill_type="solid")
    fill_caramel  = PatternFill(start_color=COLOR_CARAMEL, end_color=COLOR_CARAMEL, fill_type="solid")
    fill_parchment= PatternFill(start_color=COLOR_PARCHMENT, end_color=COLOR_PARCHMENT, fill_type="solid")
    fill_cream    = PatternFill(start_color=COLOR_CREAM, end_color=COLOR_CREAM, fill_type="solid")
    fill_card     = PatternFill(start_color=COLOR_CARD_BG, end_color=COLOR_CARD_BG, fill_type="solid")
    fill_white    = PatternFill(start_color=COLOR_WHITE, end_color=COLOR_WHITE, fill_type="solid")

    fill_status_completed = PatternFill(start_color="E8F5E9", end_color="E8F5E9", fill_type="solid")
    font_status_completed = Font(name="Segoe UI", size=9, bold=True, color="2E7D32")
    fill_status_pending   = PatternFill(start_color="FEF3C7", end_color="FEF3C7", fill_type="solid")
    font_status_pending   = Font(name="Segoe UI", size=9, bold=True, color="B45309")
    fill_status_voided    = PatternFill(start_color="FEE2E2", end_color="FEE2E2", fill_type="solid")
    font_status_voided    = Font(name="Segoe UI", size=9, bold=True, color="B91C1C")

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
        left=Side(style='thin', color="D8C8B0"),
        right=Side(style='thin', color="D8C8B0"),
        top=Side(style='thin', color="D8C8B0"),
        bottom=Side(style='thin', color="D8C8B0")
    )

    align_left = Alignment(horizontal='left', vertical='center')
    align_center = Alignment(horizontal='center', vertical='center')
    align_right = Alignment(horizontal='right', vertical='center')

    FMT_CURRENCY = '"₱"#,##0.00'
    FMT_INTEGER = '#,##0'
    FMT_PERCENT = '0.0%'

    # Aggregate metrics
    total_sales = 0.0
    total_orders = 0
    total_discounts = 0.0
    cash_sales = 0.0
    gcash_sales = 0.0
    cash_tendered = 0.0
    change_dispensed = 0.0
    vatable_sales = 0.0
    output_vat = 0.0
    vat_exempt_sales = 0.0
    total_subtotal_gross = 0.0
    total_discount_given = 0.0
    total_net_collected = 0.0
    seen_tx = set()
    category_mix = {}
    product_mix = {}

    for r in rows:
        tId = r['transaction_id']
        total = float(r['total_amount'] or 0)
        disc = float(r['discount'] or 0)
        paid = float(r['amount_paid'] or total)
        chg = float(r['change_due'] or 0)
        status = r['status']
        pay_method = r['payment_method']
        disc_type = (r['discount_type'] or 'none').lower()
        
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
                if pay_method == 'Cash':
                    cash_sales += total
                    cash_tendered += paid
                    change_dispensed += chg
                elif pay_method == 'GCash':
                    gcash_sales += total

                sub_val = float(r.get('subtotal') or total)
                disc_val = float(r.get('discount') or 0.0)
                total_subtotal_gross += sub_val
                total_discount_given += disc_val
                total_net_collected += total

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
    ws1.views.sheetView[0].showGridLines = True
    ws1.sheet_properties.tabColor = COLOR_ESPRESSO

    ws1.merge_cells("A1:G1")
    ws1.merge_cells("A2:G2")
    ws1.merge_cells("A3:G3")
    
    ws1["A1"] = "☕ DK CAFE & CAFEFLOW POS · EXECUTIVE SALES & FINANCIAL AUDIT"
    ws1["A1"].font = font_title
    ws1["A1"].fill = fill_espresso
    ws1["A1"].alignment = Alignment(horizontal='center', vertical='center')
    ws1.row_dimensions[1].height = 36

    ws1["A2"] = f"Reporting Period: {period_title} | Store: DK Cafe (Matabungkay, Lian, Batangas)"
    ws1["A2"].font = font_subtitle
    ws1["A2"].fill = fill_espresso
    ws1["A2"].alignment = Alignment(horizontal='center', vertical='center')
    ws1.row_dimensions[2].height = 18

    ws1["A3"] = f"Report Status: Official Shift Audit | Generated: {now_str} | Currency: Philippine Peso (PHP ₱)"
    ws1["A3"].font = font_subtitle
    ws1["A3"].fill = fill_espresso
    ws1["A3"].alignment = Alignment(horizontal='center', vertical='center')
    ws1.row_dimensions[3].height = 20

    ws1.row_dimensions[4].height = 12

    # KPI Summary Cards
    kpis = [
        ("A", "B", "TOTAL COMPLETED SALES", total_sales, FMT_CURRENCY, "Gross settled revenue"),
        ("C", "C", "COMPLETED ORDERS", total_orders, FMT_INTEGER, "Customer receipts"),
        ("D", "D", "AVERAGE ORDER VALUE", aov, FMT_CURRENCY, "Avg ticket size"),
        ("E", "E", "STATUTORY DISCOUNTS", total_discounts, FMT_CURRENCY, "RA 9994 / Promos"),
        ("F", "F", "CASH DRAWER TENDER", cash_tendered - change_dispensed, FMT_CURRENCY, "Net drawer cash"),
        ("G", "G", "GCASH DIGITAL SALES", gcash_sales, FMT_CURRENCY, "E-Wallet settlement")
    ]

    ws1.row_dimensions[5].height = 16
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
        ws1[f"{c_start}7"].font = Font(name="Segoe UI", size=7.5, italic=True, color="888888")
        ws1[f"{c_start}7"].alignment = align_center

        cols_range = [c_start] if c_start == c_end else [c_start, c_end]
        for c in cols_range:
            for r_idx in [5, 6, 7]:
                cell = ws1[f"{c}{r_idx}"]
                cell.fill = fill_card
                cell.border = border_card

    ws1.row_dimensions[8].height = 14

    # Payment Reconciliation Table (Left)
    ws1["A9"] = "💳 PAYMENT METHOD RECONCILIATION"
    ws1["A9"].font = font_sec_hdr
    ws1.merge_cells("A9:C9")

    # BIR Tax Table (Right)
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

    cash_orders = sum(1 for r in rows if r['status'] == 'Completed' and r['payment_method'] == 'Cash' and r['item_row_id'] == rows[0]['item_row_id']) if rows else 0
    gcash_orders = sum(1 for r in rows if r['status'] == 'Completed' and r['payment_method'] == 'GCash' and r['item_row_id'] == rows[0]['item_row_id']) if rows else 0

    pay_data = [
        ("💵 Cash at Counter", cash_orders, cash_sales),
        ("📱 GCash Digital E-Wallet", gcash_orders, gcash_sales)
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
        ws1[f"{c}13"].fill = fill_cream
        ws1[f"{c}13"].border = border_total
    ws1["B13"].number_format = FMT_INTEGER
    ws1["C13"].number_format = FMT_CURRENCY
    ws1["B13"].alignment = align_right
    ws1["C13"].alignment = align_right

    for c, h in [("E", "Tax Category"), ("F", "Rate / Status"), ("G", "Net Amount (₱)")]:
        cell = ws1[f"{c}10"]
        cell.value = h
        cell.font = font_tbl_hdr
        cell.fill = fill_espresso
        cell.alignment = align_left if c == "E" else (align_center if c == "F" else align_right)
        cell.border = border_thin

    tax_rows = [
        ("Gross Sales (Subtotal)", "Standard Menu Sales", total_subtotal_gross),
        ("Total Discounts Given", "Senior/PWD & Promo", total_discount_given),
        ("Net Sales Revenue", "Actual Revenue Collected", total_net_collected),
        ("Sales Tax / VAT", "0% Non-VAT System", 0.00),
    ]
    for idx, (t_cat, t_st, t_val) in enumerate(tax_rows, start=11):
        ws1[f"E{idx}"] = t_cat
        ws1[f"F{idx}"] = t_st
        ws1[f"G{idx}"] = t_val
        ws1[f"E{idx}"].font = font_main
        ws1[f"F{idx}"].font = Font(name="Segoe UI", size=9, color=COLOR_MUTED)
        ws1[f"G{idx}"].font = font_bold
        ws1[f"G{idx}"].number_format = FMT_CURRENCY
        ws1[f"E{idx}"].border = border_thin
        ws1[f"F{idx}"].border = border_thin
        ws1[f"G{idx}"].border = border_thin
        ws1[f"F{idx}"].alignment = align_center
        ws1[f"G{idx}"].alignment = align_right

    ws1["E15"] = "Net Certified Sales"
    ws1["F15"] = "Total Realized"
    ws1["G15"] = "=G11+G12+G13+G14"
    for c in ["E", "F", "G"]:
        ws1[f"{c}15"].font = font_total
        ws1[f"{c}15"].fill = fill_cream
        ws1[f"{c}15"].border = border_total
    ws1["G15"].number_format = FMT_CURRENCY
    ws1["F15"].alignment = align_center
    ws1["G15"].alignment = align_right

    ws1.row_dimensions[17].height = 14

    # Category Mix Table
    ws1["A18"] = "📊 PRODUCT CATEGORY PERFORMANCE MIX"
    ws1["A18"].font = font_sec_hdr
    ws1.merge_cells("A18:G18")

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
        ws1[f"D{c_row}"] = f"=C{c_row}/$C${c_row + len(sorted_cats)}"

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

    ws1.column_dimensions['A'].width = 28
    ws1.column_dimensions['B'].width = 16
    ws1.column_dimensions['C'].width = 22
    ws1.column_dimensions['D'].width = 18
    ws1.column_dimensions['E'].width = 26
    ws1.column_dimensions['F'].width = 22
    ws1.column_dimensions['G'].width = 24

    # =========================================================================
    # SHEET 2: 🧾 Itemized Sales Ledger
    # =========================================================================
    ws2 = wb.create_sheet(title="Itemized Sales Ledger")
    ws2.views.sheetView[0].showGridLines = True
    ws2.sheet_properties.tabColor = COLOR_CARAMEL

    ws2.merge_cells("A1:U1")
    ws2.merge_cells("A2:U2")
    ws2["A1"] = "DK CAFE · ITEMIZABLE TRANSACTION AUDIT & SALES LEDGER"
    ws2["A1"].font = font_title
    ws2["A1"].fill = fill_espresso
    ws2["A1"].alignment = Alignment(horizontal='center', vertical='center')
    ws2.row_dimensions[1].height = 32

    ws2["A2"] = f"Reporting Period: {period_title} | Complete line-item register | Exported {now_str}"
    ws2["A2"].font = font_subtitle
    ws2["A2"].fill = fill_espresso
    ws2["A2"].alignment = Alignment(horizontal='center', vertical='center')
    ws2.row_dimensions[2].height = 18
    ws2.row_dimensions[3].height = 8

    ledger_headers = [
        "Receipt #", "Date & Time", "Cashier", "Order Status", "Payment",
        "GCash Ref #", "Discount Type", "Senior/PWD ID", "Discount Reason",
        "Product Item", "Category", "Unit Price", "Qty", "Line Total",
        "Order Subtotal", "Order Discount", "Order Net Total", "Amount Paid",
        "Change Due", "Voided/Cancelled By", "Audit Reason / Notes"
    ]

    ws2.row_dimensions[4].height = 24
    for col_idx, h_text in enumerate(ledger_headers, start=1):
        col_letter = get_column_letter(col_idx)
        cell = ws2[f"{col_letter}4"]
        cell.value = h_text
        cell.font = font_tbl_hdr
        cell.fill = fill_espresso
        cell.border = border_thin
        cell.alignment = align_center

    l_row = 5
    for r in rows:
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
            elif col_idx in [4]:
                cell.alignment = align_center
                if status == 'Completed':
                    cell.fill = fill_status_completed
                    cell.font = font_status_completed
                elif status == 'Pending':
                    cell.fill = fill_status_pending
                    cell.font = font_status_pending
                else:
                    cell.fill = fill_status_voided
                    cell.font = font_status_voided
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
        ws2[f"A{l_row}"] = "TOTALS"
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

    for col in ws2.columns:
        col_letter = get_column_letter(col[0].column)
        max_len = 0
        for cell in col:
            val_str = str(cell.value or '')
            if cell.number_format == FMT_CURRENCY and isinstance(cell.value, (int, float)):
                val_str = f"₱{cell.value:,.2f}"
            if cell.row in [1, 2]: continue
            max_len = max(max_len, len(val_str))
        ws2.column_dimensions[col_letter].width = max(max_len + 4, 12)

    ws2.column_dimensions['A'].width = 13
    ws2.column_dimensions['B'].width = 20
    ws2.column_dimensions['J'].width = 28
    ws2.column_dimensions['K'].width = 18

    # =========================================================================
    # SHEET 3: 📦 Product Mix & Performance Ranking
    # =========================================================================
    ws3 = wb.create_sheet(title="Product Performance")
    ws3.views.sheetView[0].showGridLines = True
    ws3.sheet_properties.tabColor = COLOR_GREEN

    ws3.merge_cells("A1:G1")
    ws3.merge_cells("A2:G2")
    ws3["A1"] = "DK CAFE · PRODUCT SALES MIX & POPULARITY RANKING"
    ws3["A1"].font = font_title
    ws3["A1"].fill = fill_espresso
    ws3["A1"].alignment = Alignment(horizontal='center', vertical='center')
    ws3.row_dimensions[1].height = 32

    ws3["A2"] = f"Unit volumes, total revenues, and revenue contribution percentages | {period_title}"
    ws3["A2"].font = font_subtitle
    ws3["A2"].fill = fill_espresso
    ws3["A2"].alignment = Alignment(horizontal='center', vertical='center')
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

    ws3.row_dimensions[4].height = 24
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
        ws3[f"A{p_row}"] = rank
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
    
    ws3.freeze_panes = "A5"

    ws3.column_dimensions['A'].width = 10
    ws3.column_dimensions['B'].width = 34
    ws3.column_dimensions['C'].width = 20
    ws3.column_dimensions['D'].width = 16
    ws3.column_dimensions['E'].width = 15
    ws3.column_dimensions['F'].width = 20
    ws3.column_dimensions['G'].width = 24

    # Save to final file
    os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
    wb.save(output_path)
    print(f"SUCCESS: Exported {output_path} ({os.path.getsize(output_path):,} bytes)")

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description="CafeFlow POS Excel Report Generator")
    parser.add_argument("--output", required=True, help="Path to save output .xlsx file")
    parser.add_argument("--start", help="Start date (YYYY-MM-DD)")
    parser.add_argument("--end", help="End date (YYYY-MM-DD)")
    parser.add_argument("--date", help="Single date (YYYY-MM-DD)")
    parser.add_argument("--all", action="store_true", help="Export all historical records")
    args = parser.parse_args()

    generate_workbook(
        output_path=args.output,
        start_date=args.start,
        end_date=args.end,
        single_date=args.date,
        is_all=args.all
    )
