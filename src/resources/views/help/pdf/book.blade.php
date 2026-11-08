@php
    /**
     * @var \App\Help\Pdf\RoleBook $book
     * @var array<string, string> $bodies   slug => rendered article HTML
     * @var array<string, int> $pages       slug => printed page number (second pass; empty on the first)
     * @var array<string, string> $sectionTitles
     * @var string $issued
     */
    $entries = [];
    if ($book->guide !== []) {
        $entries[] = ['id' => 's-guide', 'title' => 'دليل الدور', 'articles' => $book->guide];
    }
    foreach ($book->sections as $key => $articles) {
        $entries[] = ['id' => 's-'.$key, 'title' => $sectionTitles[$key] ?? $key, 'articles' => $articles];
    }
@endphp
<html dir="rtl" lang="ar">
<head>
<meta charset="utf-8">
<title>دليل {{ $book->title }} — OpsOne</title>
<style>
    body { font-family: plex; font-size: 10.5pt; line-height: 1.7; color: #1f2933; }
    h1 { font-size: 20pt; color: #0E7C72; margin: 0 0 8pt 0; }
    h2 { font-size: 15pt; color: #0E7C72; margin: 0 0 4pt 0; padding-bottom: 3pt; border-bottom: 1pt solid #0E7C72; }
    h3 { font-size: 12.5pt; color: #1f2933; margin: 12pt 0 3pt 0; }
    h4, h5, h6 { font-size: 11pt; color: #1f2933; margin: 8pt 0 2pt 0; }
    p { margin: 0 0 6pt 0; }
    ul, ol { margin: 0 0 6pt 0; }
    li { margin-bottom: 2pt; }
    a { color: #0E7C72; text-decoration: none; }
    .article a { text-decoration: underline; }
    .muted { color: #6b7280; }
    .summary { color: #6b7280; margin: 0 0 8pt 0; }
    .section-title { font-size: 22pt; color: #0E7C72; margin: 0 0 14pt 0; }
    .article { margin-bottom: 22pt; }
    .fig { text-align: center; margin: 6pt 0 10pt 0; page-break-inside: avoid; }
    img { max-width: 100%; border: 0.6pt solid #d6dbe0; }
    table { border-collapse: collapse; width: 100%; margin: 4pt 0 10pt 0; page-break-inside: avoid; }
    th, td { border: 0.6pt solid #c9d0d6; padding: 4pt 6pt; text-align: right; vertical-align: top; }
    th { background-color: #eef3f2; font-weight: bold; }
    tr { page-break-inside: avoid; }
    blockquote { margin: 4pt 0 8pt 0; padding: 2pt 8pt; border-right: 2pt solid #0E7C72; color: #4b5563; }
    code { font-size: 9.5pt; background-color: #f1f3f5; }
    .cover { text-align: center; padding-top: 150pt; }
    .cover .brand { font-size: 16pt; color: #6b7280; margin-bottom: 30pt; }
    .cover .book { font-size: 30pt; font-weight: bold; color: #0E7C72; }
    .cover .role { font-size: 24pt; margin: 8pt 0 40pt 0; }
    .cover .meta { font-size: 11pt; color: #6b7280; }
    table.toc { page-break-inside: auto; }
    .toc td { border: 0; padding: 1.5pt 0; }
    .toc .sec { font-size: 13pt; font-weight: bold; color: #0E7C72; padding-top: 8pt; }
    .toc a { text-decoration: none; color: #1f2933; }
    .toc .sec a { color: #0E7C72; }
    .toc .pg { width: 30pt; text-align: left; color: #6b7280; }
</style>
</head>
<body>
<div class="cover">
    <div class="brand">OpsOne</div>
    <div class="book">دليل الدور</div>
    <div class="role">{{ $book->title }}</div>
    <div class="meta">تاريخ الإصدار: <span dir="ltr">{{ $issued }}</span></div>
    <div class="meta">للتدريب والتسليم</div>
</div>

<pagebreak />
<bookmark content="الفهرس" level="0" />
<h1>الفهرس</h1>
<table class="toc">
@foreach ($entries as $entry)
    <tr><td class="sec"><a href="#{{ $entry['id'] }}">{{ $entry['title'] }}</a></td><td class="pg sec">{{ $pages[$entry['id']] ?? '' }}</td></tr>
    @foreach ($entry['articles'] as $article)
        <tr><td><a href="#a-{{ $article->slug }}">{{ $article->titleAr }}</a></td><td class="pg">{{ $pages['a-'.$article->slug] ?? '' }}</td></tr>
    @endforeach
@endforeach
</table>

@foreach ($entries as $entry)
<pagebreak />
<bookmark content="{{ $entry['title'] }}" level="0" />
<!--anchor:{{ $entry['id'] }}--><a name="{{ $entry['id'] }}"></a><div class="section-title">{{ $entry['title'] }}</div>
    @foreach ($entry['articles'] as $article)
<bookmark content="{{ $article->titleAr }}" level="1" />
<!--anchor:a-{{ $article->slug }}--><a name="a-{{ $article->slug }}"></a><div class="article">
    <h2>{{ $article->titleAr }}</h2>
    <p class="summary">{{ $article->summaryAr }}</p>
    {!! $bodies[$article->slug] !!}
</div>
    @endforeach
@endforeach
</body>
</html>
