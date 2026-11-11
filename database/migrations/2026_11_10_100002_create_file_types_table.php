<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The file formats admins may upload (resource library, assignment files, email attachments),
 * managed in System > File types. One row per group, e.g. "Adobe Illustrator" => ai, eps, svg ...
 * Seeded with the client's "Supported File Formats" list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->json('extensions');                       // ["ai", "eps", ...], lowercase, no dot
            $table->unsignedInteger('max_size_mb')->default(50);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

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
            DB::table('file_types')->insert([
                'name' => $name,
                'extensions' => json_encode(array_map('trim', explode(',', $extensions))),
                'max_size_mb' => $maxMb,
                'is_active' => true,
                'sort_order' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_types');
    }
};
