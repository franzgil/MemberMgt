# -*- coding: utf-8 -*-
"""Erzeugt aus den 4 Markdown-Datenschutzerklärungen je ein PDF (AFOL.lu-Stil)."""
import re, html, sys
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import cm
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_LEFT
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table,
                                TableStyle, ListFlowable, ListItem)

BLUE = colors.HexColor("#006cb7"); DARK = colors.HexColor("#1a1a1a")
BORDER = colors.HexColor("#c8d2dc"); GREYBG = colors.HexColor("#eef3f8")

ss = getSampleStyleSheet()
body = ParagraphStyle("body", parent=ss["Normal"], fontName="Helvetica", fontSize=10.5,
                      leading=15, textColor=DARK, spaceAfter=7)
h1 = ParagraphStyle("h1", parent=ss["Title"], fontName="Helvetica-Bold", fontSize=18,
                    textColor=BLUE, spaceAfter=10, alignment=TA_LEFT)
h2 = ParagraphStyle("h2", parent=ss["Heading2"], fontName="Helvetica-Bold", fontSize=12.5,
                    textColor=BLUE, spaceBefore=12, spaceAfter=5)
cell = ParagraphStyle("cell", parent=body, fontSize=9.5, leading=13, spaceAfter=0)
cellb = ParagraphStyle("cellb", parent=cell, fontName="Helvetica-Bold")

def inline(t):
    t = html.escape(t)
    t = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", t)
    t = re.sub(r"`(.+?)`", r'<font face="Courier" size="9">\1</font>', t)
    t = re.sub(r"(https?://[^\s]+)", r'<font color="#006cb7">\1</font>', t)
    return t

def build(src, out):
    lines = open(src, encoding="utf-8").read().splitlines()
    story = []; i = 0
    while i < len(lines):
        ln = lines[i]
        if not ln.strip():
            i += 1; continue
        if ln.startswith("# "):
            story.append(Paragraph(inline(ln[2:]), h1))
        elif ln.startswith("## "):
            story.append(Paragraph(inline(ln[3:]), h2))
        elif ln.startswith("|"):
            rows = []
            while i < len(lines) and lines[i].startswith("|"):
                cells = [c.strip() for c in lines[i].strip().strip("|").split("|")]
                if not re.match(r"^[-: ]+$", "".join(cells)):
                    rows.append(cells)
                i += 1
            data = [[Paragraph(inline(c), cellb if r == 0 else cell) for c in row]
                    for r, row in enumerate(rows)]
            ncol = max(len(r) for r in data)
            t = Table(data, colWidths=[(16.0/ncol)*cm]*ncol, hAlign="LEFT")
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
    SimpleDocTemplate(out, pagesize=A4, topMargin=1.8*cm, bottomMargin=1.6*cm,
                      leftMargin=2*cm, rightMargin=2*cm).build(story)
    print("OK", out)

OUTDIR = sys.argv[1] if len(sys.argv) > 1 else "."
HERE = "/home/user/MemberMgt/docs"
for lg, tag in [("de","DE"), ("fr","FR"), ("en","EN"), ("lb","LB")]:
    build("%s/datenschutzerklaerung-%s.md" % (HERE, lg),
          "%s/Datenschutzerklaerung-%s.pdf" % (OUTDIR, tag))
