import pymupdf, sys
import os
os.makedirs('rev', exist_ok=True)
d = pymupdf.open(sys.argv[1]); per = 6
for i in range(0, d.page_count, per):
    sheet = pymupdf.open(); pg = sheet.new_page(width=1800, height=1300)
    for j in range(per):
        if i + j >= d.page_count: break
        pix = d[i + j].get_pixmap(dpi=55)
        x = (j % 3) * 600; y = (j // 3) * 650
        pg.insert_image(pymupdf.Rect(x + 5, y + 5, x + 595, y + 645), pixmap=pix)
    pg.get_pixmap(dpi=72).save(f'rev/h{i // per:02d}.png')
