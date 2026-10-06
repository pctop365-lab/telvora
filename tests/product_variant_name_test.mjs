import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';

// Execute the production formatter, not a copy of its implementation.
const source = await readFile(new URL('../src/components/ProductDetail.tsx', import.meta.url), 'utf8');
const ast = ts.createSourceFile('ProductDetail.tsx', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
const formatter = ast.statements.find(node => ts.isFunctionDeclaration(node) && node.name?.text === 'formatVariantProductName');
assert.ok(formatter);
const { outputText } = ts.transpileModule(formatter.getText(ast), {
  compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 },
});
const { formatVariantProductName } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);

for (const size of [50, 55, 65]) {
  const name = `Телевизор Hisense ${size}E7S PRO ${size}" QLED 4K Smart TV (2026)`;
  const code = `${size}E7S PRO`;
  assert.equal(formatVariantProductName(name, code), name);
  assert.equal(formatVariantProductName(name.replace(' PRO', ''), code), name);
  assert.equal(formatVariantProductName(name, ` ${code} `), name);
  assert.equal(formatVariantProductName(formatVariantProductName(name, code), code), name);
}
assert.equal(formatVariantProductName('Телевизор LG OLED48C6RLA 48" OLED', 'OLED48C6LA'), 'Телевизор LG OLED48C6LA 48" OLED');
assert.equal(formatVariantProductName('Телевизор Hisense 50E7S PRO', '55E7S PRO'), 'Телевизор Hisense 55E7S PRO');
assert.equal(formatVariantProductName('Телевизор Hisense 50E7S PRO', null), 'Телевизор Hisense 50E7S PRO');
assert.equal(formatVariantProductName('Телевизор без кода', '50E7S PRO'), 'Телевизор без кода');
console.log('Production variant name formatter: PASS (50/55/65E7S PRO, suffix insertion, replacement, repeat calls)');
