import pymupdf, json
d = pymupdf.open('cuerpo.pdf')
toc = [(t, pg) for lvl, t, pg in d.get_toc() if lvl == 1]
print(toc)
pg = {}
n = 0
for t, p in toc:
    if t.strip().upper() == 'ÍNDICE':
        continue
    n += 1
    pg['%02d' % n] = p
json.dump(pg, open('paginas.json', 'w'))
print(pg)
