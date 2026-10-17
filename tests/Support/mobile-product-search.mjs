import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { runInNewContext } from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import { transpileModule, ModuleKind } from 'typescript';
import { ref } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync('resources/js/mobile/pages/Products.vue', 'utf8');
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'products-search-test' });
const compiled = transpileModule(script.content, { compilerOptions: { module: ModuleKind.CommonJS } }).outputText;
const calls = [];
const page = ref(4);
const exports = {};
const dependencies = {
    '../usePaged': { usePaged: () => ({ result: ref({ data: [] }), busy: ref(false), error: ref(''), page, load: force => calls.push(force) }) },
    '../api': { money: value => String(value) },
    '../session': { session: { data: { permissions: {} } } },
    '../Icon.vue': { default: {} },
    '../BarcodeCapture.vue': { default: {} },
};
runInNewContext(compiled, { exports, require: name => dependencies[name] ?? require(name) });
const state = exports.default.setup({}, { expose() {} });

state.cameraOpen.value = true;
state.capture(' 6291108730515 ');
assert.equal(state.query.value, '6291108730515');
assert.equal(state.cameraOpen.value, false);

state.capture('late-camera-frame');
assert.equal(state.query.value, '6291108730515');

state.cameraOpen.value = true;
state.capture('6291108730515');
assert.equal(page.value, 1);
assert.deepEqual(calls, [true]);
assert.equal(state.cameraOpen.value, false);

state.cameraOpen.value = true;
state.capture('');
assert.equal(state.cameraOpen.value, true);
assert.equal(state.query.value, '6291108730515');

state.cameraOpen.value = false;
state.query.value = 'Dior';
assert.equal(state.query.value, 'Dior');
assert.match(descriptor.template.content, /<BarcodeCapture v-if="cameraOpen" @scanned="capture"/);
assert.match(descriptor.template.content, /aria-haspopup="dialog"/);
console.log('Product barcode search checks passed.');
