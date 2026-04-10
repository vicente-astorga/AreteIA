<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates rendering: common header (subtitle + progress bar),
 * card wrapper, and dispatches to the correct step class.
 */
class step_renderer {

    /** Total number of steps (0-indexed, so 0..7 = 8 steps). */
    private const TOTAL_STEPS = 7;

    /**
     * Render the complete step view (progress bar + card + step content).
     *
     * @param int       $step     Current step (0–7)
     * @param int       $id       Course ID
     * @param array     $summary  Course summary from data_provider
     * @param array     $files    Course files from data_provider
     * @param \context  $context  Moodle context
     * @param bool      $is_ajax  Whether this is an AJAX request
     */
    public static function render(
        int $step,
        int $id,
        array $summary,
        array $files,
        \context $context,
        bool $is_ajax
    ): void {
        global $PAGE;

        // Subtitle
        echo \html_writer::tag('p', 'AreteIA · Prototipo', ['class' => 'areteia-subtitle']);

        // Progress bar
        self::render_progress_bar($step);

        // Card wrapper
        echo \html_writer::start_tag('div', ['class' => 'areteia-card']);

        // Context array passed to every step
        $ctx = [
            'id'       => $id,
            'summary'  => $summary,
            'files'    => $files,
            'context'  => $context,
            'is_ajax'  => $is_ajax,
        ];

        // Dispatch to step class
        $class = "\\local_areteia\\steps\\step{$step}";
        if (class_exists($class)) {
            $class::render($ctx);
        }

        echo \html_writer::end_tag('div'); // card
    }

    /**
     * Render the dot progress bar.
     */
    private static function render_progress_bar(int $current): void {
        global $PAGE;

        echo \html_writer::start_tag('div', ['class' => 'areteia-progress']);

        for ($i = 0; $i <= self::TOTAL_STEPS; $i++) {
            $class = ($i < $current) ? 'done' : (($i == $current) ? 'active' : 'pending');
            $url   = new \moodle_url($PAGE->url, ['step' => $i]);
            echo \html_writer::link($url, $i, ['class' => "areteia-dot $class"]);

            if ($i < self::TOTAL_STEPS) {
                $line_class = ($i < $current) ? 'done' : '';
                echo \html_writer::tag('div', '', ['class' => "areteia-line $line_class"]);
            }
        }

        echo \html_writer::end_tag('div');
    }

    /**
     * Render the standard bottom navigation bar used by most steps.
     *
     * @param int           $step        Current step number
     * @param \moodle_url   $prev_url    Back button URL
     * @param \moodle_url|null $next_url Next button URL (null = no button)
     * @param string        $next_label  Label for next button
     * @param array         $next_attrs  Extra attributes for next button
     * @param string|null   $disabled_label  If set, show a disabled span instead of next button
     */
    public static function render_nav(
        int $step,
        \moodle_url $prev_url,
        ?\moodle_url $next_url = null,
        string $next_label = '',
        array $next_attrs = [],
        ?string $disabled_label = null
    ): void {
        echo \html_writer::start_tag('div', ['class' => 'areteia-nav']);
        echo \html_writer::link($prev_url, '← Anterior', ['class' => 'areteia-btn']);
        echo \html_writer::tag('span', "Paso $step de " . self::TOTAL_STEPS, ['class' => 'areteia-ncnt']);

        if ($disabled_label !== null) {
            echo \html_writer::tag('span', $disabled_label, [
                'class' => 'areteia-btn disabled',
                'style' => 'opacity:0.5; cursor:not-allowed;',
            ]);
        } else if ($next_url) {
            $attrs = array_merge(['class' => 'areteia-btn areteia-btn-primary'], $next_attrs);
            echo \html_writer::link($next_url, $next_label, $attrs);
        }

        echo \html_writer::end_tag('div');
    }
}
