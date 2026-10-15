/* eslint-disable @typescript-eslint/no-explicit-any -- the golden files are untyped JSON made by the legacy code */
// Test helper: reads the golden files made by port/phase-2/tools/make_golden.mjs from the legacy code.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import zlib from 'node:zlib';

const DIR = path.join(path.dirname(fileURLToPath(import.meta.url)), '__golden__');

export function readGolden<T = any>(name: string): T {
    return JSON.parse(zlib.gunzipSync(fs.readFileSync(path.join(DIR, name))).toString('utf8')) as T;
}

/** Results are compared as JSON (so -0 equals 0 and undefined equals absent, as in the saved golden). */
export const asJson = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;
