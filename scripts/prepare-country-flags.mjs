// Run only in the bounded source container, after extracting the pinned npm tarball.
import { getCountries } from '/workspace/node_modules/libphonenumber-js/max/index.js';
import { mkdir, copyFile, readFile, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
const target = '/workspace/public/marketing/flags';
await mkdir(target, { recursive: true });
const hashes = {};
for (const country of getCountries()) {
    const name = `${country}.svg`;
    const source = `/tmp/package/3x2/${name}`;
    const bytes = await readFile(source);
    if (/<script|\bon\w+=|(?:https?:)?\/\//i.test(bytes.toString().replace('http://www.w3.org/2000/svg', ''))) throw new Error(`Unexpected active/external content in ${name}`);
    await copyFile(source, `${target}/${name}`);
    hashes[name] = createHash('sha256').update(bytes).digest('hex');
}
await copyFile('/tmp/package/LICENSE', `${target}/LICENSE`);
const pkg = JSON.parse(await readFile('/tmp/package/package.json', 'utf8'));
await writeFile(`${target}/provenance.json`, JSON.stringify({ package: pkg.name, version: pkg.version, source: `https://registry.npmjs.org/${pkg.name}/-/${pkg.name}-${pkg.version}.tgz`, files: hashes }, null, 2) + '\n');
console.log(`${Object.keys(hashes).length} local static flag assets copied; no runtime dependency.`);
