// Run viewport/mode checks sequentially in one bounded QA container.
import { spawn } from 'node:child_process';
const phases = [['--behavior-only'],['--matrix-only','--390','--light'],['--matrix-only','--390','--dark'],['--matrix-only','--1440','--light'],['--matrix-only','--1440','--dark']];
for (const args of phases) {
    console.log('QA phase', args.join(' '));
    const child = spawn(process.execPath, ['/checks/check-appearance.mjs', ...args], { stdio: 'inherit' });
    const code = await new Promise(resolve => child.on('exit', resolve));
    if (code !== 0) process.exit(code || 1);
}
console.log('Appearance behavior and all four matrix phases passed');
