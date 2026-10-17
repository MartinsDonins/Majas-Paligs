// Lang key parity check (CI): lang/lv.json ⇔ lang/ru.json and lang/admin/lv.json ⇔ lang/admin/ru.json.
import { readFileSync } from 'node:fs';

let failed = false;

for (const dir of ['lang', 'lang/admin']) {
    const lv = Object.keys(JSON.parse(readFileSync(`${dir}/lv.json`, 'utf8')));
    const ru = Object.keys(JSON.parse(readFileSync(`${dir}/ru.json`, 'utf8')));
    const missingRu = lv.filter((k) => !ru.includes(k));
    const missingLv = ru.filter((k) => !lv.includes(k));

    if (missingRu.length || missingLv.length) {
        failed = true;
        console.error(`${dir}: missing in ru.json → ${missingRu.join(', ') || '—'}; missing in lv.json → ${missingLv.join(', ') || '—'}`);
    } else {
        console.log(`${dir}: ${lv.length} keys, LV = RU ✓`);
    }
}

process.exit(failed ? 1 : 0);
