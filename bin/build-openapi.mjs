#!/usr/bin/env node
// Builds app/modules/api/spec/openapi.json from docs/openapi/base.yaml + docs/openapi/parts/*.yaml.
//   node bin/build-openapi.mjs           write the JSON (and validate it)
//   node bin/build-openapi.mjs --check   fail if the committed JSON is stale or the document is invalid
// Needs the dev dependencies: npm install
import fs from 'node:fs';
import path from 'node:path';
import yaml from 'js-yaml';
import SwaggerParser from '@apidevtools/swagger-parser';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const out = path.join(root, 'app/modules/api/spec/openapi.json');
const check = process.argv.includes('--check');

const doc = yaml.load(fs.readFileSync(path.join(root, 'docs/openapi/base.yaml'), 'utf8'));
doc.paths = {};
doc.tags = [];
const seen = new Map();
const parts = fs.readdirSync(path.join(root, 'docs/openapi/parts')).filter((f) => f.endsWith('.yaml')).sort();

for (const f of parts) {
  const part = yaml.load(fs.readFileSync(path.join(root, 'docs/openapi/parts', f), 'utf8')) || {};
  for (const [p, item] of Object.entries(part.paths || {})) {
    if (doc.paths[p]) {
      for (const m of Object.keys(item)) {
        if (doc.paths[p][m]) throw new Error(`Duplicate operation ${m.toUpperCase()} ${p} in ${f}`);
      }
      Object.assign(doc.paths[p], item);
    } else {
      doc.paths[p] = item;
    }
  }
  for (const [name, schema] of Object.entries((part.components || {}).schemas || {})) {
    if (doc.components.schemas[name]) throw new Error(`Duplicate schema ${name} in ${f}`);
    doc.components.schemas[name] = schema;
  }
  for (const t of part.tags || []) {
    if (!doc.tags.find((x) => x.name === t.name)) doc.tags.push(t);
  }
}

for (const [p, item] of Object.entries(doc.paths)) {
  for (const [m, op] of Object.entries(item)) {
    if (!['get', 'post', 'put', 'patch', 'delete', 'options', 'head'].includes(m)) continue;
    if (!op.operationId) throw new Error(`Missing operationId: ${m.toUpperCase()} ${p}`);
    if (seen.has(op.operationId)) throw new Error(`Duplicate operationId ${op.operationId}`);
    seen.set(op.operationId, `${m.toUpperCase()} ${p}`);
  }
}

await SwaggerParser.validate(JSON.parse(JSON.stringify(doc)));
const json = JSON.stringify(doc, null, 2) + '\n';

if (check) {
  const current = fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : '';
  if (current !== json) {
    console.error('openapi.json is stale: run `node bin/build-openapi.mjs` and commit the result.');
    process.exit(1);
  }
  console.log(`openapi.json is current (${Object.keys(doc.paths).length} paths, ${seen.size} operations).`);
} else {
  fs.mkdirSync(path.dirname(out), { recursive: true });
  fs.writeFileSync(out, json);
  console.log(`Wrote ${path.relative(root, out)}: ${Object.keys(doc.paths).length} paths, ${seen.size} operations.`);
}
