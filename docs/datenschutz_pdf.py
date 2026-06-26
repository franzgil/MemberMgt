# -*- coding: utf-8 -*-
import re, html
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import cm
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_LEFT
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table,
                                TableStyle, ListFlowable, ListItem)

SRC = "/home/user/MemberMgt/docs/datenschutzerklaerung-entwurf.md"
OUT = "/tmp/claude-0/-home-user-MemberMgt/c1aba12c-30b1-5c7c-af5f-561b7dc159e6/scratchpad/Datenschutzerklaerung-Entwurf.pdf"

BLUE = colors.HexColor("#006cb7"); GOLD = colors.HexColor("#c8a951")
DARK = colors.HexColor("#1a1a1a"); BORDER = colors.HexColor("#c8d2dc")
GREYBG = colors.HexColor("#eef3f8")

ss = getSampleStyleSheet()
body = ParagraphStyle("body", parent=ss["Normal"], fontName="Helvetica", fontSize=10.5,
                      leading=15, textColor=DARK, spaceAfter=7)
h1 = ParagraphStyle("h1", parent=ss["Title"], fontName="Helvetica-Bold", fontSize=18,
                    textColor=BLUE, spaceAfter=10, alignment=TA_LEFT)
h2 = ParagraphStyle("h2", parent=ss["Heading2"], fontName="Helvetica-Bold", fontSize=12.5,
                    textColor=BLUE, spaceBefore=12, spaceAfter=5)
cell = ParagraphStyle("cell", parent=body, fontSize=9.5, leading=13, spaceAfter=0)
cellb = ParagraphStyle("cellb", parent=cell, fontName="Helvetica-Bold")
quote = ParagraphStyle("quote", parent=body, fontSize=9.5, leading=13, textColor=colors.HexColor("#7a4b16"))

def inline(t):
    t = html.escape(t)
    t = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", t)
    t = re.sub(r"`(.+?)`", r'<font face="Courier" size="9">\1</font>', t)
    t = re.sub(r"(https?://[^\s]+)", r'<font color="#006cb7">\1</font>', t)
    return t

lines = open(SRC, encoding="utf-8").read().splitlines()
story = []
i = 0
quote_buf = []

def flush_quote():
    global quote_buf
    if quote_buf:
        txt = " ".join(quote_buf).strip()
        tb = Table([[Paragraph(inline(txt), quote)]], colWidths=[16.0*cm])
        tb.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),colors.HexColor("#fff8f1")),
            ("BOX",(0,0),(-1,-1),0.6,colors.HexColor("#f0d2ad")),
            ("LINEBEFORE",(0,0),(0,-1),3,colors.HexColor("#dd8a1f")),
            ("LEFTPADDING",(0,0),(-1,-1),11),("RIGHTPADDING",(0,0),(-1,-1),10),
            ("TOPPADDING",(0,0),(-1,-1),8),("BOTTOMPADDING",(0,0),(-1,-1),8)]))
        story.append(tb); story.append(Spacer(1,6))
        quote_buf = []

while i < len(lines):
    ln = lines[i]
    if ln.startswith("> "):
        quote_buf.append(ln[2:]); i += 1; continue
    flush_quote()
    if not ln.strip():
        i += 1; continue
    if ln.startswith("# "):
        story.append(Paragraph(inline(ln[2:]), h1))
    elif ln.startswith("## "):
        story.append(Paragraph(inline(ln[3:]), h2))
    elif ln.startswith("|"):
        # Tabelle einlesen
        rows = []
        while i < len(lines) and lines[i].startswith("|"):
            cells = [c.strip() for c in lines[i].strip().strip("|").split("|")]
            if not re.match(r"^[-: ]+$", "".join(cells)):  # Trennzeile überspringen
                rows.append(cells)
            i += 1
        data = []
        for r, row in enumerate(rows):
            st = cellb if r == 0 else cell
            data.append([Paragraph(inline(c), st) for c in row])
        ncol = max(len(r) for r in data)
        w = (16.0/ncol)*cm
        t = Table(data, colWidths=[w]*ncol, hAlign="LEFT")
        t.setStyle(TableStyle([("GRID",(0,0),(-1,-1),0.6,BORDER),("VALIGN",(0,0),(-1,-1),"TOP"),
            ("BACKGROUND",(0,0),(-1,0),GREYBG),
            ("LEFTPADDING",(0,0),(-1,-1),6),("RIGHTPADDING",(0,0),(-1,-1),6),
            ("TOPPADDING",(0,0),(-1,-1),4),("BOTTOMPADDING",(0,0),(-1,-1),4)]))
        story.append(t); story.append(Spacer(1,6)); continue
    elif ln.startswith("- "):
        items = []
        while i < len(lines) and lines[i].startswith("- "):
            items.append(ListItem(Paragraph(inline(lines[i][2:]), body)))
            i += 1
        story.append(ListFlowable(items, bulletType="bullet", leftIndent=12, bulletColor=BLUE))
        continue
    else:
        story.append(Paragraph(inline(ln), body))
    i += 1
flush_quote()

SimpleDocTemplate(OUT, pagesize=A4, topMargin=1.8*cm, bottomMargin=1.6*cm,
                  leftMargin=2*cm, rightMargin=2*cm,
                  title="Datenschutzerklaerung - Entwurf").build(story)
print("OK", OUT)
