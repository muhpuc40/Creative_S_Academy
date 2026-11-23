<?php

namespace Database\Seeders;

use App\Enums\AmountType;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseContent;
use App\Models\CourseCurriculum;
use App\Models\CourseResource;
use App\Models\CourseReview;
use App\Models\Student;
use Illuminate\Database\Seeder;

/**
 * Three sample courses, one per category (php artisan db:seed --class=CourseDemoSeeder).
 * Each course: 2 stages x 2 modules, 2 videos + 1 notes link per module
 * (8 videos and 4 resource links, under the limit of 10 each).
 *
 *  full-stack-web-development  Web Development, 20% discount, price history, 3 installments
 *  flutter-app-development     Mobile App, flat discount, 2 installments
 *  python-for-data-science     Data Science & AI, no discount, full payment
 */
class CourseDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Course::withTrashed()->exists()) {
            return;
        }

        $cat = [];
        foreach (['web-development' => 'Web Development', 'mobile-app' => 'Mobile App', 'data-science-ai' => 'Data Science & AI'] as $slug => $name) {
            $cat[$slug] = CourseCategory::create(['slug' => $slug, 'name' => $name, 'description' => "{$name} courses"]);
        }

        $two = fn (string $a, string $b, string $c, string $d) => ['Beginning' => [$a, $b], 'Intermediate' => [$c, $d]];

        //            category           title                        slug                         level      status       active  price  dType      dVal  oldPrice plan     thumbnail  stages
        $courses = [
            ['web-development', 'Full Stack Web Development', 'full-stack-web-development', 'Offline', 'published', true, 12000, 'percent', 20,  15000, 'three', true, $two('HTML & CSS', 'JavaScript Basics', 'Laravel Framework', 'React Frontend')],
            ['mobile-app',      'Flutter App Development',    'flutter-app-development',    'Online',  'published', true, 10000, 'flat',    1000, null,  'two',   true, $two('Dart Language', 'Flutter Widgets', 'State Management', 'Publish to Play Store')],
            ['data-science-ai', 'Python for Data Science',    'python-for-data-science',    'Online',  'published', true, 8000,  null,      0,    null,  'full',  true, $two('Python Basics', 'NumPy & Pandas', 'Data Visualization', 'Mini Project')],
        ];

        foreach ($courses as [$catSlug, $title, $slug, $level, $status, $active, $price, $dType, $dValue, $oldPrice, $plan, $thumb, $stages]) {
            $course = Course::create([
                'course_categories_id' => $cat[$catSlug]->id,
                'title' => $title,
                'slug' => $slug,
                'description' => "{$title}: learn step by step with live classes, recorded lessons, exams and assignments. Perfect for beginners.",
                'thumbnail' => $thumb ? "https://picsum.photos/seed/{$slug}/640/360" : null,
                'preview_video' => $thumb ? 'https://example.com/preview/'.$slug.'.mp4' : null,
                'level' => $level,
                'status' => $status,
                'is_active' => $active,
            ]);

            $modules = $this->curriculum($course, $stages);

            if ($price !== null) {
                if ($oldPrice) {
                    $course->changePrice($oldPrice, null, 0, false);   // price history: old price stays in the table
                }
                $course->changePrice($price, $dType ? AmountType::from($dType) : null, $dValue, false, ['access_days' => 180, 'renewal_fee' => 2000, 'renewal_days' => 180]);
                $this->paymentPlan($course, $plan, $modules);
            }

            foreach (['Build real projects', 'Understand the core concepts', 'Work with real tools', 'Be ready for a job or freelancing'] as $i => $t) {
                $course->learningOutcomes()->create(['title' => $t, 'sort_order' => $i + 1, 'is_active' => true]);
            }
            $course->learningOutcomes()->create(['title' => 'Hidden outcome (inactive)', 'sort_order' => 9, 'is_active' => false]);

            $course->faqs()->create(['question' => 'Do I need any experience?', 'answer' => 'No, we start from zero.', 'sort_order' => 1]);
            $course->faqs()->create(['question' => 'Will I get a certificate?', 'answer' => 'Yes, after you finish the course.', 'sort_order' => 2]);
            $course->faqs()->create(['question' => 'Hidden question (inactive)', 'answer' => 'This FAQ is switched off.', 'sort_order' => 3, 'is_active' => false]);
        }

        $this->reviews();
    }

    /** Stages, modules and every item type. Returns the modules in order. */
    private function curriculum(Course $course, array $stages): array
    {
        $all = [];
        $week = 0;
        $sort = 0;

        foreach ($stages as $stageTitle => $moduleTitles) {
            $stage = CourseCurriculum::create([
                'course_list_id' => $course->id, 'title' => $stageTitle, 'description' => "{$stageTitle} stage of {$course->title}",
                'duration' => 30, 'milestone_value' => 25, 'sort_order' => ++$sort,
            ]);

            foreach ($moduleTitles as $m => $title) {
                $week++;
                $module = $stage->modules()->create(['title' => $title, 'week' => $week, 'objective' => "Learn {$title}", 'sort_order' => $m + 1]);
                $all[] = $module;

                foreach ([1, 2] as $part) {
                    $video = CourseContent::create([
                        'name' => "{$title} - Part {$part}",
                        'thumbnail' => 'https://picsum.photos/seed/'.$course->slug.$week.$part.'/320/180',
                        'url' => 'https://example.com/videos/'.$course->slug.'-'.$week.'-'.$part.'.mp4',
                    ]);
                    $module->items()->create([
                        'item_type' => 'recorded', 'course_content_id' => $video->id, 'sort_order' => $part,
                        'is_preview' => $week === 1 && $part === 1,   // first lesson is a free preview
                    ]);
                }
                $module->items()->create(['item_type' => 'live', 'objective' => "Live class: {$title}", 'sort_order' => 3]);
                $notes = CourseResource::create(['name' => "{$title} notes", 'url' => 'https://example.com/notes/'.$course->slug.'-'.$week.'.pdf']);
                $module->items()->create(['item_type' => 'resources', 'course_resource_id' => $notes->id, 'sort_order' => 4]);
                $module->items()->create(['item_type' => 'assignment', 'objective' => "Assignment: {$title}", 'sort_order' => 5]);
                $module->items()->create(['item_type' => 'exam', 'objective' => "Exam: {$title}", 'sort_order' => 6]);

                if ($week === 3) {   // an item switched off by the admin (must not show on the website)
                    $module->items()->create(['item_type' => 'live', 'objective' => 'Extra live class (inactive)', 'sort_order' => 7, 'is_active' => false]);
                }
            }
        }

        // a module switched off by the admin
        $last = CourseCurriculum::where('course_list_id', $course->id)->orderByDesc('sort_order')->first();
        $hidden = $last->modules()->create(['title' => 'Bonus module (inactive)', 'week' => $week + 1, 'sort_order' => 99, 'is_active' => false]);
        $hidden->items()->create(['item_type' => 'live', 'objective' => 'Bonus live', 'sort_order' => 1]);

        return $all;
    }

    private function paymentPlan(Course $course, string $plan, array $modules): void
    {
        /** @var \App\Models\CoursePrice $price */
        $price = $course->prices()->where('is_active', true)->first();
        $at = fn (int $i) => $modules[min($i, count($modules) - 1)]->id;

        $rules = match ($plan) {
            'two' => [['Admission', 'percent', 50, null], ['Second installment', 'percent', 50, $at(2)]],
            'three' => [['Admission', 'percent', 40, null], ['Second installment', 'percent', 30, $at(2)], ['Final installment', 'percent', 30, $at(4)]],
            'flat' => [['Admission', 'flat', 3000, null], ['Second installment', 'flat', 3000, $at(2)], ['Final installment', 'flat', 2000, $at(3)]],
            default => [],   // full payment
        };

        foreach ($rules as $i => [$name, $type, $amount, $moduleId]) {
            $price->paymentRules()->create([
                'course_list_id' => $course->id, 'course_modules_id' => $moduleId,
                'rule_name' => $name, 'amount_type' => $type, 'amount' => $amount, 'sort_order' => $i + 1,
            ]);
        }
    }

    private function reviews(): void
    {
        $students = Student::orderBy('id')->pluck('id')->all();
        if (! $students) {
            return;
        }

        $comments = ['Very clear teaching.', 'Great course, worth the money.', 'Good, but I wanted more projects.', 'The live classes helped a lot.', 'Excellent support from the instructor.'];
        // slug => ratings of approved reviews
        $plan = [
            'full-stack-web-development' => [5, 5, 4, 3],
            'flutter-app-development' => [5, 4],
            'python-for-data-science' => [4, 3],
        ];

        foreach ($plan as $slug => $ratings) {
            $course = Course::where('slug', $slug)->first();
            $n = 0;
            foreach ($ratings as $i => $rating) {
                CourseReview::create(['course_list_id' => $course->id, 'student_id' => $students[$i], 'rating' => $rating, 'comment' => $comments[$i % 5], 'status' => 'approved']);
                $n = $i + 1;
            }
            // one waiting for approval and one rejected (both must stay hidden)
            CourseReview::create(['course_list_id' => $course->id, 'student_id' => $students[$n], 'rating' => 2, 'comment' => 'Waiting for admin approval.', 'status' => 'pending']);
            CourseReview::create(['course_list_id' => $course->id, 'student_id' => $students[$n + 1], 'rating' => 1, 'comment' => 'Rejected review.', 'status' => 'rejected']);
        }
    }
}
