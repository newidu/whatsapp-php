#!/usr/bin/env python3
"""
Generate src/Proto/WAProtoSchema.php from WhiskeySockets/Baileys' WAProto.proto.

Usage:
    python3 tools/generate_proto_schema.py [path-to-WAProto.proto] [output.php]

Defaults: downloads nothing; expects a Baileys checkout proto file path.
The PHP-side codec (WAProto::encodeProto/decodeProto) is schema-driven, so
re-running this against a newer WAProto.proto upgrades message support.
"""
import re
import sys

SCALARS = {
    'double', 'float', 'int32', 'int64', 'uint32', 'uint64', 'sint32', 'sint64',
    'fixed32', 'fixed64', 'sfixed32', 'sfixed64', 'bool', 'string', 'bytes',
}


def strip_comments(text: str) -> str:
    text = re.sub(r'/\*.*?\*/', '', text, flags=re.S)
    text = re.sub(r'//[^\n]*', '', text)
    return text


def find_block_end(text: str, open_idx: int) -> int:
    depth = 0
    i = open_idx
    while i < len(text):
        c = text[i]
        if c == '"':
            # skip string literals (default values)
            i += 1
            while i < len(text) and text[i] != '"':
                if text[i] == '\\':
                    i += 1
                i += 1
        elif c == '{':
            depth += 1
        elif c == '}':
            depth -= 1
            if depth == 0:
                return i
        i += 1
    raise ValueError('unbalanced braces')


FIELD_RE = re.compile(
    r'(?:(optional|required|repeated)\s+)?'
    r'([A-Za-z_][\w.]*)\s+'
    r'([A-Za-z_]\w*)\s*=\s*(\d+)'
    r'\s*(?:\[([^\]]*)\])?\s*;'
)
MAP_RE = re.compile(
    r'map\s*<\s*([A-Za-z_]\w*)\s*,\s*([A-Za-z_][\w.]*)\s*>\s+([A-Za-z_]\w*)\s*=\s*(\d+)'
    r'\s*(?:\[([^\]]*)\])?\s*;'
)


def parse_scope(text: str, prefix: str, messages: dict, enums: dict, enum_owner: dict):
    """Parse one braced scope (or the whole file when prefix == '')."""
    i = 0
    fields = []
    n = len(text)
    while i < n:
        m = re.compile(r'\s*(message|enum|oneof|reserved|option|extensions|extend|service|rpc|syntax|package)\b').match(text, i)
        if m and m.group(1) == 'message':
            j = m.end()
            mm = re.compile(r'\s*([A-Za-z_]\w*)\s*\{').match(text, j)
            name = mm.group(1)
            open_idx = mm.end() - 1
            close_idx = find_block_end(text, open_idx)
            inner = text[open_idx + 1:close_idx]
            full = f'{prefix}.{name}' if prefix else name
            subfields = parse_scope(inner, full, messages, enums, enum_owner)
            messages[full] = subfields
            i = close_idx + 1
            continue
        if m and m.group(1) == 'enum':
            j = m.end()
            mm = re.compile(r'\s*([A-Za-z_]\w*)\s*\{').match(text, j)
            name = mm.group(1)
            open_idx = mm.end() - 1
            close_idx = find_block_end(text, open_idx)
            inner = text[open_idx + 1:close_idx]
            full = f'{prefix}.{name}' if prefix else name
            values = {}
            for em in re.compile(r'([A-Za-z_]\w*)\s*=\s*(-?\d+)\s*(?:\[[^\]]*\])?\s*;').finditer(inner):
                values[em.group(1)] = int(em.group(2))
            enums[full] = values
            enum_owner[name] = full
            i = close_idx + 1
            continue
        if m and m.group(1) == 'oneof':
            j = m.end()
            mm = re.compile(r'\s*(?:[A-Za-z_]\w*\s*)?\{').match(text, j)
            open_idx = mm.end() - 1
            close_idx = find_block_end(text, open_idx)
            inner = text[open_idx + 1:close_idx]
            fields.extend(parse_scope(inner, prefix, messages, enums, enum_owner))
            i = close_idx + 1
            continue
        # statement up to next ';'
        semi = text.find(';', i)
        brace = text.find('{', i)
        if semi == -1:
            break
        if brace != -1 and brace < semi:
            # unknown block (service etc.) — skip it
            close_idx = find_block_end(text, brace)
            i = close_idx + 1
            continue
        stmt = text[i:semi + 1].strip()
        i = semi + 1
        if not stmt or re.compile(r'^(reserved|option|syntax|package)\b').match(stmt):
            continue
        mm = MAP_RE.fullmatch(stmt)
        if mm:
            fields.append({
                'no': int(mm.group(4)), 'name': mm.group(3), 'type': 'map',
                'keyType': mm.group(1), 'valueType': mm.group(2), 'rule': 'repeated',
                'packed': False,
            })
            continue
        fm = FIELD_RE.fullmatch(stmt)
        if fm:
            opts = fm.group(5) or ''
            fields.append({
                'no': int(fm.group(4)), 'name': fm.group(3), 'type': fm.group(2),
                'rule': fm.group(1) or 'singular',
                'packed': 'packed=true' in opts.replace(' ', ''),
            })
        # else: ignore unrecognized statements
    return fields


def php_quote(s: str) -> str:
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def resolve_type(t: str, owner_msg: str, messages: dict, enums: dict, enum_owner: dict):
    """Return (kind, resolvedName) kind in scalar/enum/message."""
    if t in SCALARS:
        return 'scalar', t
    # dotted or relative: try nested resolution walking up scope
    parts = owner_msg.split('.') if owner_msg else []
    candidates = []
    for k in range(len(parts), -1, -1):
        cand = '.'.join(parts[:k] + [t]) if k > 0 else t
        candidates.append(cand)
    candidates.append(t)
    for cand in candidates:
        if cand in messages:
            return 'message', cand
        if cand in enums:
            return 'enum', cand
    base = t.split('.')[-1]
    if base in enum_owner:
        return 'enum', enum_owner[base]
    # find any message ending with the dotted suffix
    for name in messages:
        if name == t or name.endswith('.' + t) or name.split('.')[-1] == base and t == base:
            return 'message', name
    return 'message', t  # leave unresolved; codec will throw on use


def main():
    proto_path = sys.argv[1] if len(sys.argv) > 1 else 'WAProto.proto'
    out_path = sys.argv[2] if len(sys.argv) > 2 else 'src/Proto/WAProtoSchema.php'
    text = strip_comments(open(proto_path).read())
    messages, enums, enum_owner = {}, {}, {}
    parse_scope(text, '', messages, enums, enum_owner)

    lines = []
    lines.append('<?php')
    lines.append('declare(strict_types=1);')
    lines.append('')
    lines.append('namespace BaileysPHP\\Proto;')
    lines.append('')
    lines.append('/**')
    lines.append(' * AUTO-GENERATED from WhiskeySockets/Baileys WAProto.proto by')
    lines.append(' * tools/generate_proto_schema.py — do not edit by hand.')
    lines.append(' * Field format: [name, type, kind, rule, packed] where kind is')
    lines.append(" * 'scalar'|'message'|'enum'|'map:<keyType>:<valueType>'.")
    lines.append(' */')
    lines.append('final class WAProtoSchema')
    lines.append('{')
    lines.append('    /** @var array<string,array<int,array{0:string,1:string,2:string,3:string,4:bool}>> */')
    lines.append('    public const MESSAGES = [')
    for name in sorted(messages):
        flds = sorted(messages[name], key=lambda f: f['no'])
        if not flds:
            lines.append(f'        {php_quote(name)} => [],')
            continue
        lines.append(f'        {php_quote(name)} => [')
        for f in flds:
            if f['type'] == 'map':
                kind = f"map:{f['keyType']}:{f['valueType']}"
                typ = 'map'
            else:
                kind, typ = resolve_type(f['type'], name, messages, enums, enum_owner)
            rule = 'repeated' if f['rule'] == 'repeated' else 'singular'
            packed = 'true' if f.get('packed') else 'false'
            lines.append(
                f"            {f['no']} => [{php_quote(f['name'])}, {php_quote(typ)}, {php_quote(kind)}, '{rule}', {packed}],"
            )
        lines.append('        ],')
    lines.append('    ];')
    lines.append('')
    lines.append('    /** @var array<string,array<string,int>> */')
    lines.append('    public const ENUMS = [')
    for name in sorted(enums):
        vals = ', '.join(f'{php_quote(k)} => {v}' for k, v in enums[name].items())
        lines.append(f'        {php_quote(name)} => [{vals}],')
    lines.append('    ];')
    lines.append('}')
    lines.append('')

    with open(out_path, 'w') as fh:
        fh.write('\n'.join(lines))
    print(f'wrote {out_path}: {len(messages)} messages, {len(enums)} enums, '
          f'{sum(len(v) for v in messages.values())} fields')


if __name__ == '__main__':
    main()
