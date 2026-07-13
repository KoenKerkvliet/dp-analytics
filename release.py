#!/usr/bin/env python3
"""
DP Analytics release-script.

Usage (vanuit de werkmap):
    python3 release.py patch     # 1.0.0 -> 1.0.1
    python3 release.py minor     # 1.0.0 -> 1.1.0
    python3 release.py major     # 1.0.0 -> 2.0.0
    python3 release.py 1.2.5     # expliciete versie

Wat het doet (in volgorde):
    1. Leest huidige versie uit dp-analytics.php
    2. Bepaalt nieuwe versie
    3. Update versie in plugin-header + DPA_VERSION constante + readme Stable tag
    4. Bouwt ZIP rechtstreeks in ../../<nieuwe>/dp-analytics-<nieuwe>.zip
    5. Archiveert vorige versie-folder naar ../../Archief/<oude>/
    6. git add -A && git commit -m "release X.Y.Z"
    7. git tag vX.Y.Z
    8. git push origin main && git push origin vX.Y.Z

Stopt bij elke fout - niets wordt half gedaan.
"""

import os
import re
import sys
import shutil
import subprocess
import zipfile

PLUGIN_DIR     = os.path.dirname(os.path.abspath(__file__))
PLUGIN_FILE    = os.path.join(PLUGIN_DIR, 'dp-analytics.php')
README_FILE    = os.path.join(PLUGIN_DIR, 'readme.txt')
WERKMAP_DIR    = os.path.dirname(PLUGIN_DIR)
ARCHIVE_ROOT   = os.path.dirname(WERKMAP_DIR)
ZIP_SKIP_PREFIXES = ('.b64', '.deploy', '.tmp', '.git')
ZIP_SKIP_FILES = ('release.py',)


def die(msg):
    print(f"\n[release.py] ERROR: {msg}", file=sys.stderr)
    sys.exit(1)


def run(cmd, cwd=PLUGIN_DIR, check=True):
    print(f"  $ {' '.join(cmd)}")
    r = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)
    if r.stdout.strip(): print(r.stdout.strip())
    if r.stderr.strip(): print(r.stderr.strip(), file=sys.stderr)
    if check and r.returncode != 0:
        die(f"command failed (exit {r.returncode}): {' '.join(cmd)}")
    return r


def read_version():
    with open(PLUGIN_FILE, 'r', encoding='utf-8') as f:
        s = f.read()
    m1 = re.search(r"^\s*\*\s*Version:\s*(\S+)", s, re.M)
    m2 = re.search(r"DPA_VERSION',\s*'([^']+)'", s)
    if not m1 or not m2:
        die("kan huidige versie niet uit dp-analytics.php lezen")
    if m1.group(1) != m2.group(1):
        die(f"versie inconsistent: header={m1.group(1)} constant={m2.group(1)}")
    return m1.group(1)


def write_version(new):
    with open(PLUGIN_FILE, 'r', encoding='utf-8') as f:
        s = f.read()
    s = re.sub(r"(^\s*\*\s*Version:\s*)\S+", lambda m: m.group(1) + new, s, count=1, flags=re.M)
    s = re.sub(r"(DPA_VERSION',\s*')[^']+(')", lambda m: m.group(1) + new + m.group(2), s, count=1)
    with open(PLUGIN_FILE, 'w', encoding='utf-8', newline='') as f:
        f.write(s)

    if os.path.exists(README_FILE):
        with open(README_FILE, 'r', encoding='utf-8') as f:
            r = f.read()
        r2 = re.sub(r"(^Stable tag:\s*)\S+", lambda m: m.group(1) + new, r, count=1, flags=re.M)
        if r2 != r:
            with open(README_FILE, 'w', encoding='utf-8', newline='') as f:
                f.write(r2)


def bump(version, kind):
    m = re.match(r'(\d+)\.(\d+)\.(\d+)$', version)
    if not m:
        die(f"huidige versie '{version}' is geen geldig X.Y.Z")
    major, minor, patch = (int(x) for x in m.groups())
    if kind == 'patch': patch += 1
    elif kind == 'minor': minor, patch = minor + 1, 0
    elif kind == 'major': major, minor, patch = major + 1, 0, 0
    elif re.match(r'\d+\.\d+\.\d+$', kind): return kind
    else: die(f"onbekend bump-type '{kind}' (gebruik patch, minor, major, of X.Y.Z)")
    return f"{major}.{minor}.{patch}"


def build_zip(target_path):
    if os.path.exists(target_path):
        os.remove(target_path)
    count = 0
    with zipfile.ZipFile(target_path, 'w', zipfile.ZIP_DEFLATED) as zf:
        for root, dirs, files in os.walk(PLUGIN_DIR):
            if '.git' in root.split(os.sep): continue
            for f in files:
                if any(f.startswith(p) for p in ZIP_SKIP_PREFIXES): continue
                if f in ZIP_SKIP_FILES: continue
                full = os.path.join(root, f)
                arc = os.path.relpath(full, os.path.dirname(PLUGIN_DIR)).replace(os.sep, '/')
                zf.write(full, arc)
                count += 1
    return count, os.path.getsize(target_path)


def archive_previous(old):
    old_loose = os.path.join(ARCHIVE_ROOT, old)
    if not os.path.exists(old_loose):
        return
    archief_dir = os.path.join(ARCHIVE_ROOT, 'Archief')
    os.makedirs(archief_dir, exist_ok=True)
    old_archived = os.path.join(archief_dir, old)
    if os.path.exists(old_archived):
        shutil.rmtree(old_archived)
    shutil.move(old_loose, old_archived)
    print(f"  archived {old} -> Archief/")


def main():
    if len(sys.argv) != 2:
        die("usage: python3 release.py {patch|minor|major|X.Y.Z}")

    current = read_version()
    new     = bump(current, sys.argv[1])
    print(f"\n[release.py] {current}  ->  {new}\n")

    r = run(['git', 'status', '--porcelain'], check=False)
    if r.stdout.strip():
        print("[release.py] LET OP: er staan ongecommitte wijzigingen die in deze release-commit komen.\n")

    print("[1/5] Versie bijwerken in dp-analytics.php")
    write_version(new)

    print("[2/5] Vorige versie archiveren")
    archive_previous(current)

    print("[3/5] ZIP bouwen")
    new_dir = os.path.join(ARCHIVE_ROOT, new)
    os.makedirs(new_dir, exist_ok=True)
    new_zip = os.path.join(new_dir, f'dp-analytics-{new}.zip')
    n, size = build_zip(new_zip)
    print(f"  {n} entries, {size:,} bytes -> {new_zip}")

    print("[4/5] Git commit + tag")
    run(['git', 'add', '-A'])
    run(['git', 'commit', '-m', f'release {new}'])
    run(['git', 'tag', f'v{new}'])

    print("[5/5] Push naar GitHub")
    run(['git', 'push', 'origin', 'main'])
    run(['git', 'push', 'origin', f'v{new}'])

    print(f"\n[release.py] DONE - v{new} live op GitHub.")
    print("Andere sites met Git Updater pikken 'm op binnen 12 uur (of via 'Check for updates').")


if __name__ == '__main__':
    main()
