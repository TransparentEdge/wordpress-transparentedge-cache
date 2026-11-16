#!/usr/bin/env python3
"""Regenerate the translation files from the code.

  python3 tools/i18n-build.py [--add translations.json]

1. Extracts every string with tools/i18n-extract.php (PHP tokenizer).
2. Rewrites languages/flavor-edge-cache.pot.
3. Merges it into each languages/*.po: keeps existing translations, adds new
   strings (and any given in --add, a JSON {msgid: msgstr}), drops strings
   no longer in the code.
4. Compiles the .mo files.
5. Exits 1 and lists them if any string has no translation.

Requires: php, python3, polib (pip install polib).
"""
import json, os, subprocess, sys, datetime
import polib

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANG = os.path.join(ROOT, 'languages')
DOMAIN = 'flavor-edge-cache'

def extract():
    out = subprocess.check_output(['php', os.path.join(ROOT, 'tools', 'i18n-extract.php'), ROOT])
    return json.loads(out)

def version():
    for line in open(os.path.join(ROOT, 'transparent-edge-cache.php'), encoding='utf-8'):
        if line.strip().startswith('* Version:'):
            return line.split(':', 1)[1].strip()
    return ''

def main():
    add = {}
    if '--add' in sys.argv:
        add = json.load(open(sys.argv[sys.argv.index('--add') + 1], encoding='utf-8'))
    strings = extract()
    now = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M+0000')
    meta = {
        'Project-Id-Version': 'Transparent Edge Cache ' + version(),
        'POT-Creation-Date': now,
        'MIME-Version': '1.0',
        'Content-Type': 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding': '8bit',
        'Plural-Forms': 'nplurals=2; plural=(n != 1);',
        'X-Domain': DOMAIN,
    }
    pot = polib.POFile(wrapwidth=0)
    pot.metadata = dict(meta)
    for s in strings:
        e = polib.POEntry(msgid=s['msgid'], msgctxt=s.get('msgctxt'),
                          occurrences=[tuple(r.rsplit(':', 1)) for r in s['refs']])
        if 'msgid_plural' in s:
            e.msgid_plural = s['msgid_plural']
        if '%' in s['msgid']:
            e.flags.append('php-format')
        pot.append(e)
    pot.save(os.path.join(LANG, DOMAIN + '.pot'))

    missing_all = {}
    for fn in sorted(os.listdir(LANG)):
        if not fn.endswith('.po'):
            continue
        path = os.path.join(LANG, fn)
        po = polib.pofile(path, wrapwidth=0)
        old = {(e.msgctxt or '', e.msgid): e for e in po if not e.obsolete}
        new = polib.POFile(wrapwidth=0)
        new.metadata = dict(po.metadata)
        new.metadata.update({'Project-Id-Version': meta['Project-Id-Version'], 'PO-Revision-Date': now})
        missing = []
        for p in pot:
            e = polib.POEntry(msgid=p.msgid, msgctxt=p.msgctxt, occurrences=p.occurrences, flags=list(p.flags))
            if p.msgid_plural:
                e.msgid_plural = p.msgid_plural
            prev = old.get((p.msgctxt or '', p.msgid))
            if prev and (prev.msgstr or prev.msgstr_plural) and 'fuzzy' not in prev.flags:
                e.msgstr, e.msgstr_plural = prev.msgstr, prev.msgstr_plural
            elif p.msgid in add:
                e.msgstr = add[p.msgid]
            else:
                missing.append(p.msgid)
            new.append(e)
        new.save(path)
        new.save_as_mofile(path[:-3] + '.mo')
        if missing:
            missing_all[fn] = missing
        print('%s: %d strings, %d without translation' % (fn, len(new), len(missing)))
    if missing_all:
        for fn, ms in missing_all.items():
            for m in ms:
                print('  MISSING [%s] %s' % (fn, m))
        sys.exit(1)

if __name__ == '__main__':
    main()
