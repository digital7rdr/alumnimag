const test = require('node:test');
const assert = require('node:assert');
const html = require('../src/html');

test('markdown left in HTML is converted, no stray asterisks', () => {
  const out = html.fixMarkdown('<p>**A Funeral for the Brain**</p><p>* **Zero Curiosity:** They rarely ask</p><p>* **Zero Grit:** At the first knot</p><p>This is *really* good.</p>');
  assert.ok(out.includes('<h3>A Funeral for the Brain</h3>'));
  assert.ok(out.includes('<ul><li><strong>Zero Curiosity:</strong> They rarely ask</li><li><strong>Zero Grit:</strong> At the first knot</li></ul>'));
  assert.ok(out.includes('<em>really</em>'));
  assert.ok(!out.includes('*'));
});

test('image tokens survive an AI round-trip and dropped images are restored', () => {
  const source = '<p>Intro</p><p><img src="/files/7" data-file-id="7" alt=""></p><p>Middle</p><p><img src="/files/9" data-file-id="9"></p>';
  const { html: tokenised, ids } = html.imagesToTokens(source);
  assert.deepStrictEqual(ids, [7, 9]);
  assert.ok(tokenised.includes('[[IMG:7]]') && tokenised.includes('[[IMG:9]]'));
  assert.ok(!tokenised.includes('<img'));

  // The "AI" keeps image 7 but drops image 9.
  const aiOut = '<p>Intro, improved.</p><p>[[IMG:7]]</p><p>Middle, improved.</p>';
  const back = html.tokensToImages(aiOut, ids);
  assert.deepStrictEqual(back.restored, [9]);
  assert.deepStrictEqual(html.imageIdsIn(back.html).sort(), [7, 9]);
});

test('editor images regain their file id', () => {
  const out = html.normalizeImgs('<p><img src="/files/12"></p><p><img src="https://drive.example/r/abc/files/13"></p>');
  assert.deepStrictEqual(html.imageIdsIn(out), [12, 13]);
});

test('sanitiser keeps images and structure, removes scripts', () => {
  const out = html.clean('<h1>T</h1><p onclick="x()">a<script>alert(1)</script></p><img src="/files/3" data-file-id="3">');
  assert.ok(out.includes('<h2>T</h2>'));
  assert.ok(!out.includes('script') && !out.includes('onclick'));
  assert.ok(out.includes('data-file-id="3"'));
});

test('plain text becomes paragraphs', () => {
  assert.strictEqual(html.textToHtml('One\nline\n\nTwo'), '<p>One<br>line</p>\n<p>Two</p>');
});
