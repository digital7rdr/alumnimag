const test = require('node:test');
const assert = require('node:assert');
const { parseTemplate } = require('../src/folders');
const { fsName } = require('../src/storage');

test('folder template: stages, plain folders and aliases', () => {
  const t = parseTemplate('01. Raw = raw\n# comment\n\n04. Compiled = compiled\n05. Final = final\nAdverts');
  assert.deepStrictEqual(t, [
    { name: '01. Raw', stage: 'raw' },
    { name: '04. Compiled', stage: 'compiled' },
    { name: '05. Final', stage: 'published' },
    { name: 'Adverts', stage: null },
  ]);
});

test('folder template rejects unknown stages and empty templates', () => {
  assert.throws(() => parseTemplate('01. Raw = rough'), /Unknown stage/);
  assert.throws(() => parseTemplate('  \n# nothing'), /empty/);
});

test('folder names are safe on disk', () => {
  assert.strictEqual(fsName('Section 3 - Business, Entrepreneurship & Innovation'), 'Section 3 - Business, Entrepreneurship & Innovation');
  assert.strictEqual(fsName('../../etc/passwd'), 'etc-passwd');
  assert.strictEqual(fsName('a/b:c*d?'), 'a-b-c-d');
  assert.strictEqual(fsName('   '), 'untitled');
});
