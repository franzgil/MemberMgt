# -*- coding: utf-8 -*-
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import cm
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_LEFT
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table,
                                TableStyle, ListFlowable, ListItem)

OUT = "/tmp/claude-0/-home-user-MemberMgt/c1aba12c-30b1-5c7c-af5f-561b7dc159e6/scratchpad/Mitgliedsantrag-Kurzanleitung.pdf"

# AFOL.lu Palette
INK   = colors.HexColor("#1c2833")
BLUE  = colors.HexColor("#006cb7")
GOLD  = colors.HexColor("#c8a951")
RED   = colors.HexColor("#d01012")
GREEN = colors.HexColor("#237841")
DARK  = colors.HexColor("#1a1a1a")
GREYBG = colors.HexColor("#eef3f8")
BORDER = colors.HexColor("#c8d2dc")

ss = getSampleStyleSheet()
body = ParagraphStyle("body", parent=ss["Normal"], fontName="Helvetica",
                      fontSize=10.5, leading=15, textColor=DARK, spaceAfter=6)
h1 = ParagraphStyle("h1", parent=ss["Title"], fontName="Helvetica-Bold",
                    fontSize=21, textColor=BLUE, spaceAfter=2, alignment=TA_LEFT)
sub = ParagraphStyle("sub", parent=body, fontSize=10.5,
                     textColor=colors.HexColor("#555555"), spaceAfter=13)
h2 = ParagraphStyle("h2", parent=ss["Heading2"], fontName="Helvetica-Bold",
                    fontSize=13, textColor=BLUE, spaceBefore=13, spaceAfter=6)
cell = ParagraphStyle("cell", parent=body, fontSize=9.5, leading=13, spaceAfter=0)
cellb = ParagraphStyle("cellb", parent=cell, fontName="Helvetica-Bold")
note = ParagraphStyle("note", parent=body, fontSize=9.8, leading=14, spaceAfter=0)
foot = ParagraphStyle("foot", parent=body, fontSize=8,
                      textColor=colors.HexColor("#888888"))

def code(t):
    return '<font face="Courier" size="9">%s</font>' % t

def mk_table(rows, widths, header=True):
    t = Table(rows, colWidths=widths, hAlign="LEFT")
    sty = [
        ("GRID", (0,0), (-1,-1), 0.6, BORDER),
        ("VALIGN", (0,0), (-1,-1), "TOP"),
        ("LEFTPADDING", (0,0), (-1,-1), 6), ("RIGHTPADDING", (0,0), (-1,-1), 6),
        ("TOPPADDING", (0,0), (-1,-1), 4), ("BOTTOMPADDING", (0,0), (-1,-1), 4),
    ]
    if header:
        sty.append(("BACKGROUND", (0,0), (-1,0), GREYBG))
    t.setStyle(TableStyle(sty))
    return t

def callout(text, bg, line):
    tb = Table([[Paragraph(text, note)]], colWidths=[16.2*cm])
    tb.setStyle(TableStyle([
        ("BACKGROUND",(0,0),(-1,-1), bg), ("BOX",(0,0),(-1,-1),0.6, line),
        ("LINEBEFORE",(0,0),(0,-1),3, line),
        ("LEFTPADDING",(0,0),(-1,-1),12),("RIGHTPADDING",(0,0),(-1,-1),10),
        ("TOPPADDING",(0,0),(-1,-1),8),("BOTTOMPADDING",(0,0),(-1,-1),8)]))
    return tb

story = []

story.append(Paragraph("Fördermitglied-Antrag &ndash; Kurzanleitung", h1))
story.append(Paragraph("Formular %s &middot; AFOL.lu &middot; Stand 26.06.2026" % code("mitgliedsantrag.php"), sub))

story.append(Paragraph(
    "Das eigene Formular ersetzt das fehlerhafte WoltLab-Plugin und behebt dessen M&auml;ngel "
    "(keine Best&auml;tigung, keine Zahlungsaufforderung, kein Storno). Es ist 4-sprachig, "
    "im AFOL.lu-Design und schreibt direkt in die Mitgliederverwaltung.", body))

# Funktionen
story.append(Paragraph("Was das Formular kann", h2))
rows = [[Paragraph("Funktion", cellb), Paragraph("Details", cellb)],
        [Paragraph("Best&auml;tigung", cell), Paragraph("E-Mail an den Absender nach dem Absenden", cell)],
        [Paragraph("Zahlung", cell), Paragraph("&Uuml;berweisungsdaten (IBAN) <b>und</b> SumUp-Button (Online-Karte)", cell)],
        [Paragraph("Storno", cell), Paragraph("Pers&ouml;nlicher Link zum Zur&uuml;ckziehen des Antrags", cell)],
        [Paragraph("Sprachen", cell), Paragraph("L&euml;tzebuergesch &middot; Deutsch &middot; Fran&ccedil;ais &middot; English (Umschalter); Newsletter-Sprachfeld", cell)],
        [Paragraph("Ohne Forenkonto", cell), Paragraph("G&auml;ste k&ouml;nnen beantragen; Eingeloggte werden verkn&uuml;pft", cell)]]
story.append(mk_table(rows, [4.2*cm, 12.0*cm]))

# Ablauf
story.append(Paragraph("Ablauf der Mitgliedschaft", h2))
items = [
    "Antrag absenden &rarr; gespeichert als <b>F&ouml;rdermitglied</b> (Status %s, Typ %s)." % (code("antrag"), code("foerder")),
    "Absender erh&auml;lt eine E-Mail mit Kontodaten + SumUp-Link + Storno-Link.",
    "Zahlung (15&nbsp;&euro;) per &Uuml;berweisung <b>oder</b> SumUp.",
    "Tr&eacute;sorier best&auml;tigt den Zahlungseingang &rarr; Status wird %s." % code("aktiv"),
    "<b>Aktivmitglied</b> (Typ %s) macht der Vorstand sp&auml;ter &ndash; fr&uuml;hestens nach 6 Monaten." % code("aktiv"),
]
story.append(ListFlowable([ListItem(Paragraph(t, body), value=i+1) for i,t in enumerate(items)],
                          bulletType="1", leftIndent=14))

# Kontodaten
story.append(Paragraph("Vereinskonto (15 € Jahresbeitrag)", h2))
konto = [[Paragraph("Empf&auml;nger", cellb), Paragraph("AFOL.LU A.S.B.L.", cell)],
         [Paragraph("IBAN", cellb), Paragraph(code("LU34 0099 7800 0129 6664"), cell)],
         [Paragraph("BIC", cellb), Paragraph("CCRALULL (Banque Raiffeisen)", cell)],
         [Paragraph("SumUp", cellb), Paragraph(code("https://pay.sumup.com/b2c/Q3MX52O5"), cell)]]
story.append(mk_table(konto, [3.6*cm, 12.6*cm], header=False))

# Einrichtung
story.append(Paragraph("Einmalige Einrichtung", h2))
setup = [
    "<b>Datei hochladen:</b> %s nach %s (neben %s)." % (code("mitgliedsantrag.php"), code(".../public_html/afol55/"), code("global.php")),
    "<b>Datenbank</b> (beide einmal ausf&uuml;hren): %s und %s." % (code("sql/alter_mitglieder_zurueckgezogen.sql"), code("sql/alter_mitglieder_newsletter_sprache.sql")),
    "<b>Geheimnis:</b> %s muss gesetzt sein (dasselbe wie bei der Mitgliedskarte)." % code("card-secret.txt"),
    "<b>Forum:</b> den alten Plugin-Link durch %s ersetzen." % code(".../afol55/mitgliedsantrag.php"),
]
story.append(ListFlowable([ListItem(Paragraph(t, body), value=i+1) for i,t in enumerate(setup)],
                          bulletType="1", leftIndent=14))

# Test
story.append(Paragraph("Pr&uuml;fen", h2))
story.append(callout(
    "Als Administrator %s aufrufen: zeigt IBAN-Status, Spalte %s, Mail-Versandmethode. "
    "Mit %s wird eine Test-Mail an die eigene Adresse gesendet. "
    "Danach das Formular selbst absenden und Best&auml;tigungsmail + Storno-Link pr&uuml;fen."
    % (code(".../mitgliedsantrag.php?page=test"), code("newsletter_sprache"), code("&amp;mail=1")),
    colors.HexColor("#f6f8fa"), BLUE))

# Hinweis Mail/Cron
story.append(Spacer(1, 6))
story.append(callout(
    "<b>E-Mail-Versand:</b> WoltLab verschickt Mails &uuml;ber die Hintergrund-Warteschlange. "
    "Das Skript st&ouml;&szlig;t sie sofort an, sodass die Best&auml;tigung direkt rausgeht. "
    "F&uuml;r den Dauerbetrieb empfiehlt sich ein cPanel-Cronjob auf %s."
    % code("cron.php"),
    colors.HexColor("#fff8f1"), colors.HexColor("#dd8a1f")))

# DSGVO
story.append(Paragraph("DSGVO / Datenschutz", h2))
gdpr = [
    "<b>Pflicht-Einwilligung:</b> Absenden nur mit H&auml;kchen „Datenschutzerkl&auml;rung gelesen &amp; Verarbeitung zugestimmt\" (Link konfigurierbar via %s)." % code("ANTRAG_DATENSCHUTZ_URL"),
    "<b>Newsletter = Opt-in:</b> separate, nicht vorausgew&auml;hlte Einwilligung; Sprache wird nur bei Zustimmung gespeichert.",
    "<b>Transparenz:</b> Hinweis zu Zweck, keiner Weitergabe und Betroffenenrechten auf Formular und in der Mail.",
    "<b>Nachweis:</b> Einwilligung wird mit Zeitstempel in %s dokumentiert." % code("bemerkung"),
    "<b>Datenschutzerkl&auml;rung:</b> Entwurf liegt unter %s &ndash; Inhalt auf der verlinkten Seite ver&ouml;ffentlichen." % code("docs/datenschutzerklaerung-entwurf.md"),
]
story.append(ListFlowable([ListItem(Paragraph(t, body)) for t in gdpr],
                          bulletType="bullet", leftIndent=12, bulletColor=BLUE))

# Seitenübersicht
story.append(Paragraph("Seiten&uuml;bersicht", h2))
pages = [[Paragraph("Aufruf", cellb), Paragraph("Funktion", cellb)],
         [Paragraph(code("mitgliedsantrag.php"), cell), Paragraph("&Ouml;ffentliches Antragsformular (4 Sprachen)", cell)],
         [Paragraph(code("?page=storno&amp;t=...&amp;lang=..."), cell), Paragraph("Antrag zur&uuml;ckziehen (Link aus der Mail)", cell)],
         [Paragraph(code("?page=test"), cell), Paragraph("Diagnose (nur Admin); %s sendet Test-Mail" % code("&amp;mail=1"), cell)]]
story.append(mk_table(pages, [7.4*cm, 8.8*cm]))

story.append(Spacer(1, 12))
story.append(Paragraph("AFOL.lu &middot; Mitgliederverwaltung &middot; mitgliedsantrag.php (Version 2026-06-26-antrag10)", foot))

SimpleDocTemplate(OUT, pagesize=A4, topMargin=1.7*cm, bottomMargin=1.5*cm,
                  leftMargin=2*cm, rightMargin=2*cm,
                  title="Foerdermitglied-Antrag - Kurzanleitung").build(story)
print("OK", OUT)
