<?php
/**
 * File: includes/Video_Publishing_Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Administrator UI for R46 site/backend publication defaults. */
final class Video_Publishing_Admin
{
    public const PAGE_SLUG = 'argent-video-publishing';
    public const ACTION_SAVE = 'argent_video_save_publishing_defaults';
    private const NONCE_ACTION = 'argent_video_save_publishing_defaults';

    public function __construct(
        private readonly Video_Publishing_Defaults_Store $store,
        private readonly Backend_Registry $registry
    ) {
    }

    public function menu(): void
    {
        add_options_page(
            __('ArgentWolf Video Publishing', 'argentwolf-video-processor'),
            __('AWVP Video Publishing', 'argentwolf-video-processor'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'page')
        );
    }

    public function save_action(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage video publishing defaults.', 'argentwolf-video-processor'));
        }
        check_admin_referer(self::NONCE_ACTION);

        $input = isset($_POST['awvp_publishing']) && is_array($_POST['awvp_publishing'])
            ? wp_unslash($_POST['awvp_publishing'])
            : array();
        $desired = $this->form_to_settings($input);
        $result = $this->store->save($desired);
        $notice = match ($result['status'] ?? '') {
            Video_Publishing_Defaults_Store::APPLIED,
            Video_Publishing_Defaults_Store::PRESENT => 'saved',
            Video_Publishing_Defaults_Store::INDETERMINATE => 'indeterminate',
            default => 'refused',
        };

        wp_safe_redirect(
            add_query_arg(
                array('page' => self::PAGE_SLUG, 'awvp_publishing_notice' => $notice),
                admin_url('options-general.php')
            )
        );
        exit;
    }

    public function page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage video publishing defaults.', 'argentwolf-video-processor'));
        }

        $settings = $this->store->get();
        $backends = $this->active_peertube_backends();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ArgentWolf Video Publishing', 'argentwolf-video-processor'); ?></h1>
            <?php $this->render_notice(); ?>
            <p><?php esc_html_e('These defaults prefill new videos only. Existing and legacy videos keep their stored destination; missing legacy destination metadata always means WordPress/local.', 'argentwolf-video-processor'); ?></p>
            <p><?php esc_html_e('PeerTube-bound posts remain locally playable while the remote copy uploads and processes. Early PeerTube uploads remain private until the WordPress post is actually published, and each video still requires explicit metadata/moderation review before remote dispatch.', 'argentwolf-video-processor'); ?></p>
            <?php if (null === $settings) : ?>
                <div class="notice notice-error"><p><?php esc_html_e('The stored publishing-defaults record is malformed or from a future schema. AWVP preserved it and will not overwrite it from this page.', 'argentwolf-video-processor'); ?></p></div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SAVE); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>

                    <h2><?php esc_html_e('Site defaults for new videos', 'argentwolf-video-processor'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="awvp-default-destination"><?php esc_html_e('Default video destination', 'argentwolf-video-processor'); ?></label></th>
                            <td>
                                <?php $selected_backend = (string) ($settings['default_destination']['backend_id'] ?? Backend_Registry::LOCAL_ID); ?>
                                <select id="awvp-default-destination" name="awvp_publishing[default_backend_id]">
                                    <option value="<?php echo esc_attr(Backend_Registry::LOCAL_ID); ?>" <?php selected($selected_backend, Backend_Registry::LOCAL_ID); ?>><?php esc_html_e('WordPress / local', 'argentwolf-video-processor'); ?></option>
                                    <?php foreach ($backends as $backend_id => $backend) : ?>
                                        <option value="<?php echo esc_attr($backend_id); ?>" <?php selected($selected_backend, $backend_id); ?>><?php echo esc_html($this->backend_label($backend)); ?></option>
                                    <?php endforeach; ?>
                                    <?php if (Backend_Registry::LOCAL_ID !== $selected_backend && ! isset($backends[$selected_backend])) : ?>
                                        <option value="<?php echo esc_attr($selected_backend); ?>" selected disabled><?php echo esc_html(sprintf(__('Unavailable backend: %s', 'argentwolf-video-processor'), $selected_backend)); ?></option>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Changing this affects only newly-created, unfrozen videos. It never migrates existing videos.', 'argentwolf-video-processor'); ?></p>
                            </td>
                        </tr>
                        <?php $site = $settings['site']; ?>
                        <tr>
                            <th scope="row"><label for="awvp-final-privacy"><?php esc_html_e('Default final PeerTube visibility', 'argentwolf-video-processor'); ?></label></th>
                            <td>
                                <select id="awvp-final-privacy" name="awvp_publishing[site][final_privacy_id]">
                                    <?php foreach ($this->privacy_choices() as $id => $label) : ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected((string) $site['final_privacy_id'], $id); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('This is the final visibility after WordPress publication. Early uploads are held private first.', 'argentwolf-video-processor'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('PeerTube provider defaults', 'argentwolf-video-processor'); ?></th>
                            <td>
                                <label><?php esc_html_e('Licence ID', 'argentwolf-video-processor'); ?> <input name="awvp_publishing[site][licence_id]" type="number" min="1" step="1" value="<?php echo esc_attr((string) $site['licence_id']); ?>" style="width:7em"></label>
                                &nbsp;
                                <label><?php esc_html_e('Category ID', 'argentwolf-video-processor'); ?> <input name="awvp_publishing[site][category_id]" type="number" min="1" step="1" value="<?php echo esc_attr((string) $site['category_id']); ?>" style="width:7em"></label>
                                &nbsp;
                                <label><?php esc_html_e('Language', 'argentwolf-video-processor'); ?> <input name="awvp_publishing[site][language]" type="text" maxlength="35" value="<?php echo esc_attr((string) $site['language']); ?>" placeholder="en" style="width:8em"></label>
                                <p class="description"><?php esc_html_e('Licence/category may be left blank. Provider IDs are instance vocabulary values; backend-specific overrides below take precedence. The block/editor checkpoint will validate discovered vocabularies against the selected PeerTube instance.', 'argentwolf-video-processor'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="awvp-comments-policy"><?php esc_html_e('Comments', 'argentwolf-video-processor'); ?></label></th>
                            <td>
                                <select id="awvp-comments-policy" name="awvp_publishing[site][comments_policy]">
                                    <option value="enabled" <?php selected($site['comments_policy'], 'enabled'); ?>><?php esc_html_e('Enabled', 'argentwolf-video-processor'); ?></option>
                                    <option value="disabled" <?php selected($site['comments_policy'], 'disabled'); ?>><?php esc_html_e('Disabled', 'argentwolf-video-processor'); ?></option>
                                    <option value="approval_required" <?php selected($site['comments_policy'], 'approval_required'); ?>><?php esc_html_e('Requires approval', 'argentwolf-video-processor'); ?></option>
                                </select>
                                &nbsp;
                                <label><input name="awvp_publishing[site][download_enabled]" type="checkbox" value="1" <?php checked((bool) $site['download_enabled']); ?>> <?php esc_html_e('Allow PeerTube downloads', 'argentwolf-video-processor'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="awvp-dispatch-policy"><?php esc_html_e('Default send timing', 'argentwolf-video-processor'); ?></label></th>
                            <td>
                                <select id="awvp-dispatch-policy" name="awvp_publishing[site][dispatch_policy]">
                                    <option value="<?php echo esc_attr(PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH); ?>" <?php selected($site['dispatch_policy'], PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH); ?>><?php esc_html_e('Send when the post is scheduled or published', 'argentwolf-video-processor'); ?></option>
                                    <option value="<?php echo esc_attr(PeerTube_Publication_Plan::DISPATCH_SEND_NOW); ?>" <?php selected($site['dispatch_policy'], PeerTube_Publication_Plan::DISPATCH_SEND_NOW); ?>><?php esc_html_e('Send now after per-video review', 'argentwolf-video-processor'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="awvp-default-support"><?php esc_html_e('Default support preset', 'argentwolf-video-processor'); ?></label></th>
                            <td>
                                <select id="awvp-default-support" name="awvp_publishing[site][support_preset_id]">
                                    <option value="" <?php selected($site['support_preset_id'], ''); ?>><?php esc_html_e('None', 'argentwolf-video-processor'); ?></option>
                                    <?php foreach ($settings['support_presets'] as $preset_id => $preset) : ?>
                                        <option value="<?php echo esc_attr($preset_id); ?>" <?php selected($site['support_preset_id'], $preset_id); ?>><?php echo esc_html($preset['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('The preset Markdown is resolved/frozen when a remote publication operation is created; later preset edits do not mutate that operation.', 'argentwolf-video-processor'); ?></p>
                            </td>
                        </tr>
                        <?php $moderation = $site['moderation']; ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Sensitive-content prefill', 'argentwolf-video-processor'); ?></th>
                            <td>
                                <label><input name="awvp_publishing[site][moderation][sensitive]" type="checkbox" value="1" <?php checked((bool) $moderation['sensitive']); ?>> <?php esc_html_e('Prefill as sensitive', 'argentwolf-video-processor'); ?></label><br>
                                <label><?php esc_html_e('Summary/reason', 'argentwolf-video-processor'); ?> <input name="awvp_publishing[site][moderation][reason]" type="text" maxlength="<?php echo esc_attr((string) PeerTube_Publication_Plan::MAX_SENSITIVE_REASON_CHARACTERS); ?>" value="<?php echo esc_attr((string) $moderation['reason']); ?>" style="width:32em"></label><br>
                                <label><input name="awvp_publishing[site][moderation][violent]" type="checkbox" value="1" <?php checked((bool) $moderation['violent']); ?>> <?php esc_html_e('Potentially violent', 'argentwolf-video-processor'); ?></label><br>
                                <label><input name="awvp_publishing[site][moderation][sexually_explicit]" type="checkbox" value="1" <?php checked((bool) $moderation['sexually_explicit']); ?>> <?php esc_html_e('Potentially sexually explicit', 'argentwolf-video-processor'); ?></label>
                                <p class="description"><?php esc_html_e('This is only an editor prefill. Every PeerTube video still requires an explicit sensitive-content review before AWVP considers it dispatch-ready.', 'argentwolf-video-processor'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <h2><?php esc_html_e('PeerTube backend defaults', 'argentwolf-video-processor'); ?></h2>
                    <?php if (array() === $backends) : ?>
                        <p><?php esc_html_e('No active PeerTube backends are available. Connect and activate one before configuring backend-specific publication defaults.', 'argentwolf-video-processor'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped" style="max-width:1100px">
                            <thead><tr><th><?php esc_html_e('Backend', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Channel ID', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Final privacy', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Licence', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Category', 'argentwolf-video-processor'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($backends as $backend_id => $backend) : ?>
                                <?php $override = $settings['backend_overrides'][$backend_id] ?? array('channel_id'=>'','final_privacy_id'=>null,'licence_id'=>null,'category_id'=>null); ?>
                                <tr>
                                    <td><strong><?php echo esc_html($this->backend_label($backend)); ?></strong></td>
                                    <td><input name="awvp_publishing[backend_overrides][<?php echo esc_attr($backend_id); ?>][channel_id]" type="number" min="1" step="1" value="<?php echo esc_attr((string) ($override['channel_id'] ?? '')); ?>" placeholder="<?php echo esc_attr((string) ($backend['default_destination'] ?? '')); ?>" style="width:8em"><br><span class="description"><?php esc_html_e('Blank uses the channel selected when this backend was activated.', 'argentwolf-video-processor'); ?></span></td>
                                    <td><select name="awvp_publishing[backend_overrides][<?php echo esc_attr($backend_id); ?>][final_privacy_id]">
                                        <option value="inherit" <?php selected($override['final_privacy_id'] ?? null, null); ?>><?php esc_html_e('Inherit site', 'argentwolf-video-processor'); ?></option>
                                        <?php foreach ($this->privacy_choices() as $id => $label) : ?><option value="<?php echo esc_attr($id); ?>" <?php selected($override['final_privacy_id'] ?? null, $id); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                                    </select></td>
                                    <td><?php $this->provider_override_select($backend_id, 'licence_id', $override['licence_id'] ?? null); ?></td>
                                    <td><?php $this->provider_override_select($backend_id, 'category_id', $override['category_id'] ?? null); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h2><?php esc_html_e('Support presets', 'argentwolf-video-processor'); ?></h2>
                    <p><?php esc_html_e('Define reusable PeerTube support text. PeerTube accepts Markdown-capable support text; the individual video may choose none, a preset, or custom text.', 'argentwolf-video-processor'); ?></p>
                    <table class="widefat striped" style="max-width:1100px">
                        <thead><tr><th><?php esc_html_e('Preset ID', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Label', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Markdown', 'argentwolf-video-processor'); ?></th></tr></thead>
                        <tbody>
                        <?php $rows = array(); foreach ($settings['support_presets'] as $preset_id => $preset) { $rows[] = array('id'=>$preset_id) + $preset; } $rows[] = array('id'=>'','label'=>'','markdown'=>''); ?>
                        <?php foreach ($rows as $index => $row) : ?>
                            <tr>
                                <td><input name="awvp_publishing[support_presets][<?php echo esc_attr((string) $index); ?>][id]" type="text" maxlength="64" value="<?php echo esc_attr($row['id']); ?>" pattern="[a-z0-9][a-z0-9_-]*" placeholder="creator-support"></td>
                                <td><input name="awvp_publishing[support_presets][<?php echo esc_attr((string) $index); ?>][label]" type="text" maxlength="<?php echo esc_attr((string) Video_Publishing_Defaults::MAX_SUPPORT_LABEL_CHARACTERS); ?>" value="<?php echo esc_attr($row['label']); ?>"></td>
                                <td><textarea name="awvp_publishing[support_presets][<?php echo esc_attr((string) $index); ?>][markdown]" rows="4" cols="55"><?php echo esc_textarea($row['markdown']); ?></textarea></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description"><?php esc_html_e('Save once after entering the blank row to create a preset; the page will provide another blank row after reload. Clear all three fields of an existing row to remove that preset.', 'argentwolf-video-processor'); ?></p>

                    <?php submit_button(__('Save video publishing defaults', 'argentwolf-video-processor')); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function form_to_settings(array $input): array
    {
        $default_backend = Backend_Identity::sanitize($input['default_backend_id'] ?? null);
        $destination = Backend_Registry::LOCAL_ID === $default_backend
            ? Video_Destination::local()
            : array('version' => Video_Destination::VERSION, 'backend_id' => $default_backend);

        $site_input = is_array($input['site'] ?? null) ? $input['site'] : array();
        $moderation_input = is_array($site_input['moderation'] ?? null) ? $site_input['moderation'] : array();
        $sensitive = ! empty($moderation_input['sensitive']);
        $moderation = array(
            'sensitive'         => $sensitive,
            'reason'            => $sensitive && is_string($moderation_input['reason'] ?? null) ? $moderation_input['reason'] : '',
            'violent'           => $sensitive && ! empty($moderation_input['violent']),
            'sexually_explicit' => $sensitive && ! empty($moderation_input['sexually_explicit']),
        );

        $presets = array();
        $preset_rows = is_array($input['support_presets'] ?? null) ? $input['support_presets'] : array();
        foreach ($preset_rows as $row) {
            if (! is_array($row)) {
                return array();
            }
            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            $label = is_string($row['label'] ?? null) ? $row['label'] : '';
            $markdown = is_string($row['markdown'] ?? null) ? $row['markdown'] : '';
            if ('' === $id && '' === $label && '' === $markdown) {
                continue;
            }
            if ('' === $id || isset($presets[$id])) {
                return array();
            }
            $presets[$id] = array('label' => $label, 'markdown' => $markdown);
        }

        $overrides = array();
        $override_rows = is_array($input['backend_overrides'] ?? null) ? $input['backend_overrides'] : array();
        foreach ($override_rows as $backend_id => $row) {
            if (! is_string($backend_id) || ! is_array($row)) {
                return array();
            }
            $privacy = ($row['final_privacy_id'] ?? 'inherit') === 'inherit' ? null : ($row['final_privacy_id'] ?? null);
            $overrides[$backend_id] = array(
                'channel_id'       => is_string($row['channel_id'] ?? null) ? $row['channel_id'] : '',
                'final_privacy_id' => $privacy,
                'licence_id'       => $this->provider_override_from_form($row['licence_id'] ?? null),
                'category_id'      => $this->provider_override_from_form($row['category_id'] ?? null),
            );
        }

        return array(
            'version'             => Video_Publishing_Defaults::VERSION,
            'default_destination' => $destination,
            'site'                => array(
                'final_privacy_id'  => is_string($site_input['final_privacy_id'] ?? null) ? $site_input['final_privacy_id'] : '',
                'licence_id'        => is_string($site_input['licence_id'] ?? null) ? $site_input['licence_id'] : '',
                'category_id'       => is_string($site_input['category_id'] ?? null) ? $site_input['category_id'] : '',
                'language'          => is_string($site_input['language'] ?? null) ? $site_input['language'] : '',
                'comments_policy'   => is_string($site_input['comments_policy'] ?? null) ? $site_input['comments_policy'] : '',
                'download_enabled'  => ! empty($site_input['download_enabled']),
                'support_preset_id' => is_string($site_input['support_preset_id'] ?? null) ? $site_input['support_preset_id'] : '',
                'dispatch_policy'   => is_string($site_input['dispatch_policy'] ?? null) ? $site_input['dispatch_policy'] : '',
                'moderation'        => $moderation,
            ),
            'backend_overrides'   => $overrides,
            'support_presets'     => $presets,
        );
    }

    private function provider_override_from_form(mixed $value): string|null
    {
        if ('inherit' === $value) {
            return null;
        }
        if ('none' === $value) {
            return '';
        }
        return is_string($value) ? $value : '__invalid__';
    }

    /** @return array<string,array<string,mixed>> */
    private function active_peertube_backends(): array
    {
        $result = array();
        foreach ($this->registry->all() as $backend_id => $backend) {
            if (
                is_string($backend_id)
                && is_array($backend)
                && Backend_Registry::PEERTUBE_TYPE === ($backend['type'] ?? null)
                && 'active' === ($backend['state'] ?? null)
                && '' !== PeerTube_Connection_Input::destination_id($backend['default_destination'] ?? null)
            ) {
                $result[$backend_id] = $backend;
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @param array<string,mixed> $backend */
    private function backend_label(array $backend): string
    {
        $label = is_string($backend['label'] ?? null) ? $backend['label'] : (string) ($backend['id'] ?? '');
        $origin = is_array($backend['config'] ?? null) && is_string($backend['config']['origin'] ?? null)
            ? $backend['config']['origin']
            : '';
        return '' === $origin ? $label : $label . ' — ' . $origin;
    }

    /** @return array<string,string> */
    private function privacy_choices(): array
    {
        return array(
            '1' => __('Public', 'argentwolf-video-processor'),
            '2' => __('Unlisted', 'argentwolf-video-processor'),
            '3' => __('Private', 'argentwolf-video-processor'),
            '4' => __('Internal', 'argentwolf-video-processor'),
        );
    }

    private function provider_override_select(string $backend_id, string $field, mixed $value): void
    {
        $selected = null === $value ? 'inherit' : ('' === $value ? 'none' : (string) $value);
        ?>
        <input
            name="awvp_publishing[backend_overrides][<?php echo esc_attr($backend_id); ?>][<?php echo esc_attr($field); ?>]"
            type="text"
            value="<?php echo esc_attr($selected); ?>"
            pattern="(?:inherit|none|[1-9][0-9]*)"
            style="width:8em"
            aria-label="<?php echo esc_attr(sprintf(__('%s override', 'argentwolf-video-processor'), $field)); ?>"
        ><br><span class="description"><?php esc_html_e('Use inherit, none, or a numeric provider ID.', 'argentwolf-video-processor'); ?></span>
        <?php
    }

    private function render_notice(): void
    {
        $notice = isset($_GET['awvp_publishing_notice']) && is_string($_GET['awvp_publishing_notice'])
            ? sanitize_key(wp_unslash($_GET['awvp_publishing_notice']))
            : '';
        $message = match ($notice) {
            'saved' => __('Video publishing defaults saved.', 'argentwolf-video-processor'),
            'indeterminate' => __('AWVP could not verify whether the publishing-defaults save committed. Reload and inspect the current values before retrying.', 'argentwolf-video-processor'),
            'refused' => __('Publishing defaults were not saved. Check backend references, provider IDs, support presets, and stored-schema compatibility.', 'argentwolf-video-processor'),
            default => '',
        };
        if ('' !== $message) {
            $class = 'saved' === $notice ? 'notice notice-success is-dismissible' : 'notice notice-error';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
        }
    }
}

// EOF: includes/Video_Publishing_Admin.php
