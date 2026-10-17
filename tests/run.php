<?php
// Unit tests that need no database:  php tests/run.php
declare(strict_types=1);

putenv('ALUMNIMAG_ENV=/dev/null');
require dirname(__DIR__) . '/app/bootstrap.php';

$fails = 0;
function t(string $name, bool $ok): void
{
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) $fails++;
}

// .env parsing
$env = Config::parse("A=1\nB=\"two words\"\nC='x#y'\nD=val # comment\n# skip\nexport E=5\nF=\"a#b\"\n");
t('.env values, quotes and comments', $env === ['A' => '1', 'B' => 'two words', 'C' => 'x#y', 'D' => 'val', 'E' => '5', 'F' => 'a#b']);

// Markdown clean-up
t('bold paragraph becomes a heading', Html::fixMarkdown('<p>**Big idea**</p>') === '<h3>Big idea</h3>');
t('bullet paragraphs become a list', Html::fixMarkdown('<p>* one</p><p>* two</p>') === '<ul><li>one</li><li>two</li></ul>');
t('bullet lines become a list', Html::fixMarkdown('<p>* one<br>* two</p>') === '<ul><li>one</li><li>two</li></ul>');
t('inline bold and italic', Html::fixMarkdown('<p>a **b** and *c*</p>') === '<p>a <strong>b</strong> and <em>c</em></p>');

// Photo tokens: the AI can never lose a photo
$tok = Html::imagesToTokens('<p>x</p><p>' . Html::imgTag(5) . '</p><figure>' . Html::imgTag(7) . '<figcaption>c</figcaption></figure>');
t('photos become tokens', count(array_intersect($tok['ids'], [5, 7])) === 2 && str_contains($tok['html'], '[[IMG:5]]') && str_contains($tok['html'], '<p>[[IMG:7]]</p>'));
$back = Html::tokensToImages('<p>edited</p><p>[[IMG:5]]</p>', [5, 7]);
t('dropped photo is re-attached', $back['restored'] === [7] && substr_count($back['html'], 'data-file-id') === 2);
t('editor img links restored', Html::normalizeImgs('<img src="https://drive.x/files/12">') === Html::imgTag(12));

// Sanitiser
t('scripts and handlers removed', Html::clean('<p onclick="x()">Hi<script>alert(1)</script></p>') === '<p>Hi</p>');
t('b/i renamed, unknown tags unwrapped', Html::clean('<div><b>B</b> <i>I</i> <font>F</font></div>') === '<strong>B</strong> <em>I</em> F');
t('javascript: links removed', Html::clean('<a href="javascript:alert(1)">x</a>') === '<a>x</a>');
t('photo kept with data-file-id', Html::clean(Html::imgTag(3)) === Html::imgTag(3));
t('unicode kept', Html::clean('<p>Café – “quotes” ₹</p>') === '<p>Café – “quotes” ₹</p>');
t('plain text', Html::textToHtml("a\nb\n\nc <d>") === "<p>a<br>b</p>\n<p>c &lt;d&gt;</p>");

// Folder template and names
t('folder template', Folders::parseTemplate("01. Raw = raw\n# c\n05. Final = final\nAdverts") === [
    ['name' => '01. Raw', 'stage' => 'raw'], ['name' => '05. Final', 'stage' => 'published'], ['name' => 'Adverts', 'stage' => null]]);
try { Folders::parseTemplate('X = rough'); t('unknown stage refused', false); } catch (AppError $e) { t('unknown stage refused', true); }
t('safe folder names', Storage::fsName('../../etc/passwd') === 'etc-passwd' && Storage::fsName('a/b:c*d?') === 'a-b-c-d' && Storage::fsName('  ') === 'untitled');
t('section folder name keeps &', Storage::fsName('Section 9 - Light & Colour') === 'Section 9 - Light & Colour');

// Word conversion (built in memory)
$zipFile = tempnam(sys_get_temp_dir(), 'tst');
$z = new ZipArchive();
$z->open($zipFile, ZipArchive::OVERWRITE);
$W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"';
$z->addFromString('word/document.xml', "<w:document $W><w:body>"
    . '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Title here</w:t></w:r></w:p>'
    . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">Bold </w:t></w:r><w:r><w:t>text</w:t></w:r></w:p>'
    . '<w:p><w:r><w:t>Anchored photo:</w:t></w:r><w:r><w:drawing><wp:anchor><a:graphic><a:graphicData><a:blip r:embed="rId5"/></a:graphicData></a:graphic></wp:anchor></w:drawing></w:r></w:p>'
    . '</w:body></w:document>');
$z->addFromString('word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId5" Type="image" Target="media/image1.png"/></Relationships>');
$z->addFromString('word/media/image1.png', 'PNG1');
$z->addFromString('word/media/image2.jpeg', 'JPG2');
$z->addFromString('word/media/image3.emf', 'EMF3');
$z->close();
$saved = [];
$r = Docx::convert((string) file_get_contents($zipFile), function ($bytes, $name) use (&$saved) {
    if (str_ends_with($name, '.emf')) return null;
    $saved[] = $name;
    return 100 + count($saved);
});
@unlink($zipFile);
t("Word: heading and bold", str_contains($r['html'], '<h2>Title here</h2>') && str_contains($r['html'], '<p><strong>Bold </strong>text</p>'));
t('Word: anchored photo stays after its paragraph', str_contains($r['html'], "<p>Anchored photo:</p>\n<p>" . Html::imgTag(101) . '</p>'));
t('Word: unreferenced photo added at end, EMF kept aside', $r['extra'] === 1 && $r['skipped'] === 1 && str_ends_with($r['html'], Html::imgTag(102) . '</p>'));

echo $fails ? "\n$fails test(s) failed\n" : "\nAll tests passed\n";
exit($fails ? 1 : 0);
