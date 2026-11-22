<?php

namespace Database\Seeders;

use App\Models\FileType;
use Illuminate\Database\Seeder;

/**
 * The client's "Supported File Formats" list, shown in System > File types.
 * Runs in every environment. A group that already exists (same name) is left alone,
 * so running the seeder again never undoes an admin's changes.
 */
class FileTypeSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['Adobe Illustrator', 'ai, ait, eps, svg, pdf, dxf, png, jpg, jpeg, tif, psd', 200],
            ['Adobe Photoshop', 'psd, psb, png, jpg, jpeg, tif, gif, pdf, eps', 200],
            ['Lectra Modaris', 'mdl, iba, dxf, aama, astm', 50],
            ['Gerber AccuMark', 'dxf, zip, plt, tmp, rul', 50],
            ['Optitex PDS', 'pds, dxf, plt, opt', 50],
            ['CLO3D', 'zprj, zpac, obj, fbx, glb, gltf, abc, dxf', 500],
            ['Blender', 'blend, fbx, obj, stl, dae, abc, usd, glb, gltf', 500],
            ['Microsoft Excel', 'xlsx, xls, xlsm, csv, ods', 20],
            ['Documents', 'docx, doc, rtf, txt, odt, pdf', 20],
            ['Images', 'png, jpg, jpeg, gif, webp, svg, tif, bmp', 10],
            ['Videos', 'mp4, mov, avi, mkv, wmv, webm', 500],
            ['Microsoft PowerPoint', 'pptx, ppt, ppsx, potx, pdf', 50],
            ['Wilcom E4', 'emb, e4, dst, pes, jef, exp, vp3, hus, xxx', 20],
            ['Compressed Files', 'zip, rar, 7z, tar, gz', 500],
            ['Software Installation Files', 'exe, msi, apk, xapk, dmg, pkg, deb, rpm, appimage, run', 1024],
        ];

        foreach ($groups as $i => [$name, $extensions, $maxMb]) {
            FileType::firstOrCreate(['name' => $name], [
                'extensions' => array_map('trim', explode(',', $extensions)),
                'max_size_mb' => $maxMb,
                'is_active' => true,
                'sort_order' => $i + 1,
            ]);
        }
    }
}
