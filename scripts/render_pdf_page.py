"""Renders one PDF page to a PNG file. Called as a subprocess from
ChartVisionService so chart/figure pages can be read by a local vision
model, since nothing in PHP on this machine can rasterize a PDF page.

Usage: python render_pdf_page.py <pdf_path> <page_number_1_based> <output_png_path> [dpi]
"""
import sys

import pymupdf

pdf_path, page_number, output_path = sys.argv[1], int(sys.argv[2]), sys.argv[3]
dpi = int(sys.argv[4]) if len(sys.argv) > 4 else 150

doc = pymupdf.open(pdf_path)
page = doc[page_number - 1]
pix = page.get_pixmap(dpi=dpi)
pix.save(output_path)
