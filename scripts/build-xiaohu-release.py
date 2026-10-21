#!/usr/bin/env python3
from __future__ import annotations
import argparse, hashlib, json, re, zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / 'app' / 'Plugin' / 'XiaohuTheme'
BLOCKED = {'runtime', 'Db', 'Snapshot', '.git', '__pycache__'}
SECRET_LINE = re.compile(r"(?im)^(?P<indent>\s*)(?P<key>['\"][^'\"\r\n]*(?:api[_-]?key|app[_-]?key|access[_-]?token|refresh[_-]?token|password|passwd|secret|credential)[^'\"\r\n]*['\"]\s*=>)\s*(?P<value>'[^'\r\n]*'|\"[^\"\r\n]*\"|[^,\r\n]+)(?P<tail>\s*,?)")
STATUS_LINE = re.compile(r"(?im)^(\s*(?:['\"]STATUS['\"]|STATUS)\s*=>\s*)(?:'[^']*'|\"[^\"]*\"|[0-9]+)(\s*,?)")

def clean_config(text: str) -> str:
    text = SECRET_LINE.sub(lambda m: m.group('indent') + m.group('key') + " ''" + m.group('tail'), text)
    return STATUS_LINE.sub(r"\1'0'\2", text)

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--version', required=True)
    parser.add_argument('--output-dir', required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', args.version):
        raise SystemExit('version must be semantic MAJOR.MINOR.PATCH')
    out = Path(args.output_dir).resolve()
    out.mkdir(parents=True, exist_ok=True)
    archive = out / f'XiaohuTheme-{args.version}.zip'
    if not PLUGIN.is_dir():
        raise SystemExit(f'missing plugin source: {PLUGIN}')
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for path in sorted(PLUGIN.rglob('*')):
            if not path.is_file() or path.is_symlink():
                continue
            rel = path.relative_to(PLUGIN)
            if any(part in BLOCKED for part in rel.parts) or path.name in {'.env', '.DS_Store', 'runtime.log'}:
                continue
            if rel.as_posix() == 'Config/Config.php':
                continue
            if rel.as_posix() == 'Config/Info.php':
                data = ("<?php\ndeclare(strict_types=1);\nreturn ['NAME'=>'Xiaohu 小虎主题套件','AUTHOR'=>'Xiaohu','VERSION'=>'" + args.version + "','WEB_SITE'=>'#','DESCRIPTION'=>'一键安装并启用小虎主题与五个配套插件；通过独立 GitHub Releases 检查和安装更新。默认模板 Cartoon 独立保留。'];\n").encode()
            else:
                data = path.read_bytes()
                if rel.as_posix().startswith('Bundle/Plugins/') and rel.parts[-2:] == ('Config', 'Config.php'):
                    data = clean_config(data.decode('utf-8')).encode('utf-8')
            zf.writestr(f'app/Plugin/XiaohuTheme/{rel.as_posix()}', data)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    size = archive.stat().st_size
    manifest = {
        'schema': 1, 'plugin': 'XiaohuTheme', 'version': args.version,
        'tag': f'xiaohu-v{args.version}',
        'core': {'min': '3.8.4', 'max': '3.8.4'},
        'archive': {'name': archive.name, 'size': size, 'sha256': digest},
    }
    (out / 'xiaohu-update.json').write_text(json.dumps(manifest, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')
    print(json.dumps({'archive': str(archive), 'size': size, 'sha256': digest, 'manifest': str(out / 'xiaohu-update.json')}, ensure_ascii=False))

if __name__ == '__main__':
    main()
