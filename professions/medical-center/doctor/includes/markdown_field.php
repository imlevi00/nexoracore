<?php
/**
 * Renders a Markdown-enabled clinical note field (History / Examination /
 * Diagnoses) for the doctor consultation form. Each field is a plain
 * <textarea> progressively enhanced by doctor-ui.js into a small editor with a
 * formatting toolbar (bold, italic, headings, lists, …) and a live Preview tab.
 *
 * The stored value is raw Markdown; it is rendered back to HTML with
 * medicalCenterRenderMarkdown() on the view/history side.
 *
 * Optionally each field can carry an image-attachment strip (History /
 * Examination / Diagnoses images). When enabled, existing attachments are shown
 * as thumbnails and doctor-ui.js wires the "Add image" control to the
 * api/prescription_image.php endpoint (upload + delete).
 */

if (!function_exists('medicalDoctorRenderAttachmentThumb')) {
    /**
     * One attachment thumbnail. In editable strips a delete button is shown; the
     * image itself opens in the shared lightbox.
     *
     * @param array<string,mixed> $attachment ['id','url','original_name']
     */
    function medicalDoctorRenderAttachmentThumb(array $attachment, bool $editable): string
    {
        $enc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $url = (string)($attachment['url'] ?? '');
        if ($url === '') {
            return '';
        }
        $id = (int)($attachment['id'] ?? 0);
        $name = (string)($attachment['original_name'] ?? '');
        $alt = $name !== '' ? $name : 'Attachment';

        $html = '<div class="sc-attach-thumb" data-attach-id="' . $id . '">';
        $html .= '<a class="sc-attach-thumb-link" href="' . $enc($url) . '"'
            . ' data-sc-lightbox data-caption="' . $enc($name) . '">';
        $html .= '<img src="' . $enc($url) . '" alt="' . $enc($alt) . '" loading="lazy">';
        $html .= '</a>';
        if ($editable) {
            $html .= '<button type="button" class="sc-attach-del" data-attach-del'
                . ' title="Remove image" aria-label="Remove image"><i class="bi bi-x-lg"></i></button>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('medicalDoctorRenderAttachmentStrip')) {
    /**
     * A read-only gallery of a section's attachments (used on the view page and
     * anywhere notes are shown). Returns '' when there is nothing to show.
     *
     * @param array<int,array<string,mixed>> $attachments
     */
    function medicalDoctorRenderAttachmentStrip(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }
        $html = '<div class="sc-attach-gallery">';
        foreach ($attachments as $attachment) {
            $html .= medicalDoctorRenderAttachmentThumb($attachment, false);
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('medicalDoctorRenderMarkdownField')) {
    /**
     * @param string $name        POST field name (also the DB column).
     * @param string $label       Panel title shown to the doctor.
     * @param string $icon        Bootstrap icon class, e.g. "bi-journal-text".
     * @param string $value       Current raw Markdown value (already un-escaped).
     * @param string $placeholder Textarea placeholder.
     * @param bool   $required    Whether the field is required.
     * @param bool   $enableImages Render the image-attachment strip for this field.
     * @param string $section     Attachment section key (defaults to $name).
     * @param array<int,array<string,mixed>> $attachments Existing attachments.
     */
    function medicalDoctorRenderMarkdownField(
        string $name,
        string $label,
        string $icon,
        string $value,
        string $placeholder,
        bool $required,
        bool $enableImages = false,
        string $section = '',
        array $attachments = []
    ): string {
        $enc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $fieldId = 'md-field-' . preg_replace('/[^a-z0-9_-]/i', '', $name);
        $req = $required ? ' required' : '';
        $reqMark = $required ? ' <span class="sc-md-req" title="Required">*</span>' : '';
        $section = $section !== '' ? $section : $name;

        $tools = [
            ['bold',    'bi-type-bold',   'Bold'],
            ['italic',  'bi-type-italic', 'Italic'],
            ['heading', 'bi-type-h2',     'Heading'],
            ['ul',      'bi-list-ul',     'Bulleted list'],
            ['ol',      'bi-list-ol',     'Numbered list'],
            ['quote',   'bi-quote',       'Quote'],
            ['code',    'bi-code-slash',  'Inline code'],
            ['link',    'bi-link-45deg',  'Link'],
        ];

        $toolButtons = '';
        foreach ($tools as [$cmd, $ic, $title]) {
            $toolButtons .= '<button type="button" class="sc-md-btn" data-md-cmd="' . $enc($cmd) . '"'
                . ' title="' . $enc($title) . '" aria-label="' . $enc($title) . '">'
                . '<i class="bi ' . $enc($ic) . '"></i></button>';
        }

        // Image attachment strip (optional).
        $attachHtml = '';
        if ($enableImages) {
            $thumbs = '';
            foreach ($attachments as $attachment) {
                $thumbs .= medicalDoctorRenderAttachmentThumb($attachment, true);
            }
            $attachHtml = '<div class="sc-attach" data-md-attach data-section="' . $enc($section) . '">'
                . '<div class="sc-attach-grid" data-attach-grid>' . $thumbs . '</div>'
                . '<label class="sc-attach-add" data-attach-add>'
                . '<input type="file" accept="image/*" multiple hidden data-attach-input>'
                . '<i class="bi bi-image"></i><span>Add image</span>'
                . '</label>'
                . '<div class="sc-attach-status" data-attach-status hidden></div>'
                . '</div>';
        }

        return '<div class="sc-panel sc-md-panel mb-3">'
            . '<div class="sc-panel-header"><i class="bi ' . $enc($icon) . '"></i> ' . $enc($label) . $reqMark . '</div>'
            . '<div class="sc-panel-body sc-md-body">'
            . '<div class="sc-md-editor" data-md-editor>'
            . '<div class="sc-md-toolbar">'
            . '<div class="sc-md-tools">' . $toolButtons . '</div>'
            . '<div class="sc-md-tabs">'
            . '<button type="button" class="sc-md-tab is-active" data-md-tab="write">Write</button>'
            . '<button type="button" class="sc-md-tab" data-md-tab="preview">Preview</button>'
            . '</div>'
            . '</div>'
            . '<textarea id="' . $enc($fieldId) . '" name="' . $enc($name) . '"'
            . ' class="form-control sc-md-input" rows="4" placeholder="' . $enc($placeholder) . '"' . $req . '>'
            . $enc($value)
            . '</textarea>'
            . '<div class="sc-md-preview" data-md-preview hidden></div>'
            . '</div>'
            . '<div class="sc-md-hint"><i class="bi bi-markdown"></i> Markdown supported — '
            . '<code>**bold**</code>, <code>*italic*</code>, <code># heading</code>, lists</div>'
            . $attachHtml
            . '</div>'
            . '</div>';
    }
}
