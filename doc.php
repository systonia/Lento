<?php

/**
 * Patched PHP Markdown Doc Generator
 * - Parses namespaces, classes, docblocks, attributes, and properties
 * - Flattens property attributes for correct table rendering
 */

$srcDir = __DIR__ . '/src';
$outDir = __DIR__ . '/docs-md';
$templateFile = __DIR__ . '/class-template.md';

if (!file_exists($templateFile)) {
    die("Template file class-template.md not found.\n");
}
$template = file_get_contents($templateFile);

function parseSource($srcDir) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
    $classes = [];
    foreach ($rii as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;

        $code = file_get_contents($file->getPathname());

        // Namespace
        preg_match('/namespace\s+([^;]+);/', $code, $nm);
        $namespace = $nm[1] ?? '';

        // Class (with optional attributes and docblock)
        preg_match('/(\/\*\*.*?\*\/)?\s*((#\[(.*?)\]\s*)*)class\s+([A-Za-z0-9_]+)/s', $code, $cm);
        $docblock = trim($cm[1] ?? '');
        $attrRaw = $cm[2] ?? '';
        $class = $cm[5] ?? null;

        // Parse class attributes
        preg_match_all('/#\[(.*?)\]/s', $attrRaw, $attrs);
        $attributes = array_map(function($a) {
            $split = preg_split('/\(/',$a,2);
            return [
                'name' => trim($split[0]),
                'args' => isset($split[1]) ? trim($split[1], ')') : '',
            ];
        }, $attrs[1] ?? []);

        // Properties (with docblock, type, attributes)
        preg_match_all('/(\/\*\*.*?\*\/)?\s*(#\[(.*?)\]\s*)?(public|protected|private)\s+([\w\\\|]+)?\s*\$([A-Za-z0-9_]+)/s', $code, $props, PREG_SET_ORDER);
        $propsData = [];
        foreach ($props as $p) {
            // Attributes for property
            $pAttrs = [];
            if (!empty($p[3])) {
                $split = preg_split('/\(/',$p[3],2);
                $pAttrs[] = [
                    'name' => trim($split[0]),
                    'args' => isset($split[1]) ? trim($split[1], ')') : '',
                ];
            }
            // Flatten attributes to a string for Markdown (fixes table rendering)
            $attributesString = implode(', ', array_column($pAttrs, 'name'));

            $propsData[] = [
                'name'      => $p[6],
                'type'      => trim($p[5] ?? 'mixed'),
                'docblock'  => trim($p[1] ?? ''),
                'attributes'=> $pAttrs,
                'attributesString' => $attributesString,
            ];
        }

        if ($class) {
            $fqcn = $namespace ? "$namespace\\$class" : $class;
            $classes[$fqcn] = [
                'namespace'  => $namespace,
                'class'      => $class,
                'docblock'   => $docblock,
                'attributes' => $attributes,
                'properties' => $propsData,
                'file'       => $file->getPathname(),
            ];
        }
    }
    return $classes;
}

/**
 * Simple Markdown template renderer supporting {{var}} and {{#each ...}}...{{/each}}
 */
function renderTemplate($tpl, $vars) {
    // Nested each for attributes and properties' attributesString
    $tpl = preg_replace_callback('/{{#each (\w+)}}(.*?){{\/each}}/s', function($m) use ($vars) {
        $arr = $vars[$m[1]] ?? [];
        $out = '';
        foreach ($arr as $item) {
            $block = $m[2];
            // Replace {{attributesString}} and other direct keys
            $block = preg_replace_callback('/{{(\w+)}}/', fn($n) => $item[$n[1]] ?? '', $block);
            $out .= $block;
        }
        return $out;
    }, $tpl);

    // Replace {{key}}
    $tpl = preg_replace_callback('/{{(\w+)}}/', fn($m) => $vars[$m[1]] ?? '', $tpl);

    return $tpl;
}

// ------------------- MAIN ------------------------

$data = parseSource($srcDir);

foreach ($data as $fqcn => $info) {
    $folder = $outDir . '/' . str_replace('\\', '/', $info['namespace']);
    if (!is_dir($folder)) mkdir($folder, 0777, true);
    $filePath = "$folder/{$info['class']}.md";
    file_put_contents($filePath, renderTemplate($template, $info));
}

echo "Docs generated in $outDir\n";
