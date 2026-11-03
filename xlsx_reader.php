<?php
// قارئ ملفات .xlsx مبسط — بدون الحاجة إلى Composer أو PhpSpreadsheet.
// يقرأ جدول النصوص المشتركة وخلايا كل ورقة عمل داخل مصفوفات بسيطة.
// ملاحظة: خلايا التاريخ الرقمية تُعاد كرقم تسلسلي خام من Excel،
// لذا اجعل عمود التاريخ بنص (TEXT) واكتب التواريخ بصيغة YYYY-MM-DD
// (أو احذف العمود واستخدم حقل التاريخ الاحتياطي في نافذة الاستيراد).

function xlsx_col_to_index($letters) {
    $col = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $col = $col * 26 + (ord($letters[$i]) - 64);
    }
    return $col - 1; // zero-based
}

function read_xlsx($path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception("تعذر فتح الملف المرفوع كملف .xlsx.");
    }

    // Shared string table
    $shared = [];
    $ssRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssRaw !== false) {
        $ssXml = simplexml_load_string($ssRaw);
        foreach ($ssXml->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) $text .= (string)$run->t;
                $shared[] = $text;
            }
        }
    }

    // Sheet order from workbook.xml
    $wbRaw = $zip->getFromName('xl/workbook.xml');
    if ($wbRaw === false) throw new Exception("ملف workbook.xml مفقود — قد يكون الملف تالفًا.");
    $wbXml = simplexml_load_string($wbRaw);
    $wbXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $sheetList = [];
    foreach ($wbXml->sheets->sheet as $sheet) {
        $rAttrs = $sheet->attributes('r', true);
        $sheetList[] = ['name' => (string)$sheet['name'], 'rid' => (string)$rAttrs['id']];
    }

    // rId -> file path
    $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $relMap = [];
    if ($relsRaw !== false) {
        $relsXml = simplexml_load_string($relsRaw);
        foreach ($relsXml->Relationship as $rel) {
            $relMap[(string)$rel['Id']] = (string)$rel['Target'];
        }
    }

    $sheets = [];
    foreach ($sheetList as $s) {
        $target = $relMap[$s['rid']] ?? null;
        if (!$target) continue;
        $target = ltrim($target, '/');
        $path2 = strpos($target, 'xl/') === 0 ? $target : ('xl/' . $target);
        $sheetRaw = $zip->getFromName($path2);
        if ($sheetRaw === false) continue;

        $sheetXml = simplexml_load_string($sheetRaw);
        $rows = [];
        if (isset($sheetXml->sheetData->row)) {
            foreach ($sheetXml->sheetData->row as $row) {
                $rowData = [];
                foreach ($row->c as $c) {
                    $ref = (string)$c['r'];
                    if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) continue;
                    $colIdx = xlsx_col_to_index($m[1]);
                    $type = (string)$c['t'];
                    $vRaw = isset($c->v) ? (string)$c->v : '';

                    if ($type === 's') {
                        $val = $shared[(int)$vRaw] ?? '';
                    } else {
                        $val = $vRaw;
                    }
                    $rowData[$colIdx] = $val;
                }
                $rows[] = $rowData;
            }
        }
        $sheets[] = ['name' => $s['name'], 'rows' => $rows];
    }

    $zip->close();
    return $sheets;
}
