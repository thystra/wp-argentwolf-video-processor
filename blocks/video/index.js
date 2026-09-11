(function (wp) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element || !wp.components || !wp.blockEditor || !wp.apiFetch || !wp.data) {
        return;
    }

    const { registerBlockType } = wp.blocks;
    const { createElement: el, useEffect, useState } = wp.element;
    const {
        Button, CheckboxControl, Notice, PanelBody, SelectControl, Spinner,
        TextControl, TextareaControl, ToggleControl
    } = wp.components;
    const { InspectorControls, MediaUpload, MediaUploadCheck, useBlockProps } = wp.blockEditor;
    const { __ } = wp.i18n;
    const { select, useSelect } = wp.data;
    const apiFetch = wp.apiFetch;
    const API_ROOT = '/argentwolf-video-processor/v1/editor/videos';

    function boundedError(error) {
        if (error && typeof error.message === 'string' && error.message.length > 0) {
            return error.message.slice(0, 300);
        }
        return __('AWVP could not complete the editor request.', 'argentwolf-video-processor');
    }

    function destinationValue(state) {
        if (!state || !state.video || !state.video.destination_valid || !state.video.destination) {
            return '';
        }
        return state.video.destination.backend_id === 'local'
            ? 'local'
            : 'backend:' + state.video.destination.backend_id;
    }

    function destinationOptions(state) {
        const options = [];
        if (state && state.video && !state.video.destination_valid) {
            options.push({
                label: __('Choose a destination…', 'argentwolf-video-processor'),
                value: '',
                disabled: true
            });
        }
        if (state && state.site_default) {
            options.push({
                label: __('Use site default', 'argentwolf-video-processor') + ' — ' + state.site_default.label,
                value: 'site_default'
            });
        }
        if (state && Array.isArray(state.destinations)) {
            state.destinations.forEach(function (destination) {
                options.push({
                    label: destination.type === 'peertube'
                        ? __('PeerTube', 'argentwolf-video-processor') + ' — ' + destination.label
                        : destination.label,
                    value: destination.backend_id === 'local' ? 'local' : 'backend:' + destination.backend_id
                });
            });
        }
        return options;
    }

    function clone(value) {
        return value ? JSON.parse(JSON.stringify(value)) : null;
    }

    function choiceOptions(rows, emptyLabel) {
        const options = [];
        if (typeof emptyLabel === 'string') {
            options.push({ label: emptyLabel, value: '' });
        }
        (Array.isArray(rows) ? rows : []).forEach(function (row) {
            options.push({ label: row.label, value: row.id });
        });
        return options;
    }

    function tagsText(tags) {
        return Array.isArray(tags) ? tags.join('\n') : '';
    }

    function parseTags(text) {
        return String(text || '').split(/\r?\n/).map(function (tag) { return tag.trim(); }).filter(Boolean);
    }

    function protectedEditorStatus(status) {
        return ['publish', 'future', 'private'].indexOf(String(status || '')) !== -1;
    }

    function editorialGateState(videoId, state, publication, publicationDraft, editorPostId) {
        if (videoId < 1) {
            return { applicable: true, ready: false, detail: __('Choose a WordPress video for this block.', 'argentwolf-video-processor') };
        }
        if (!state || !state.video) {
            return { applicable: true, ready: false, detail: __('AWVP is still validating this video.', 'argentwolf-video-processor') };
        }
        const originPostId = Number(state.video.origin_post_id || 0);
        if (originPostId > 0 && editorPostId > 0 && originPostId !== editorPostId) {
            return { applicable: false, ready: true, detail: '' };
        }
        if (originPostId < 1 || editorPostId < 1) {
            return { applicable: true, ready: false, detail: __('The video has no valid publication anchor.', 'argentwolf-video-processor') };
        }
        if (!state.video.destination_valid || !state.video.destination) {
            return { applicable: true, ready: false, detail: __('Choose or repair the final destination.', 'argentwolf-video-processor') };
        }
        if (state.video.destination.backend_id === 'local') {
            return { applicable: true, ready: true, detail: '' };
        }
        if (!publication || !publicationDraft) {
            return { applicable: true, ready: false, detail: __('Save and review the PeerTube publication plan.', 'argentwolf-video-processor') };
        }
        const persistedDraft = publication.draft || null;
        const dirty = JSON.stringify(publicationDraft) !== JSON.stringify(persistedDraft);
        if (dirty) {
            return { applicable: true, ready: false, detail: __('Save the pending PeerTube publication-plan edits before publishing.', 'argentwolf-video-processor') };
        }
        if (publication.plan_status !== 'present' || publication.ready_for_dispatch !== true) {
            const missing = Array.isArray(publication.missing_review) ? publication.missing_review.join(', ') : '';
            return {
                applicable: true,
                ready: false,
                detail: missing
                    ? __('Needs review:', 'argentwolf-video-processor') + ' ' + missing
                    : __('Complete the required PeerTube publication review.', 'argentwolf-video-processor')
            };
        }
        return { applicable: true, ready: true, detail: '' };
    }

    function Edit(props) {
        const { attributes, setAttributes } = props;
        const videoId = Number(attributes.videoId || 0);
        const blockProps = useBlockProps();
        const [state, setState] = useState(null);
        const [loading, setLoading] = useState(videoId > 0);
        const [saving, setSaving] = useState(false);
        const [error, setError] = useState('');
        const [publication, setPublication] = useState(null);
        const [publicationDraft, setPublicationDraft] = useState(null);
        const [publicationLoading, setPublicationLoading] = useState(false);
        const [publicationSaving, setPublicationSaving] = useState(false);
        const [publicationError, setPublicationError] = useState('');
        const [replaceExisting, setReplaceExisting] = useState(false);
        const [tagsDraftText, setTagsDraftText] = useState('');

        const editorPost = useSelect(function (selectStore) {
            const editor = selectStore('core/editor');
            return {
                id: Number(editor && editor.getCurrentPostId ? editor.getCurrentPostId() || 0 : 0),
                status: String(editor && editor.getEditedPostAttribute ? editor.getEditedPostAttribute('status') || '' : '')
            };
        }, []);

        useEffect(function () {
            let active = true;
            if (videoId < 1) {
                setState(null);
                setLoading(false);
                return function () { active = false; };
            }
            setLoading(true);
            setError('');
            apiFetch({ path: API_ROOT + '/' + videoId })
                .then(function (response) { if (active) setState(response); })
                .catch(function (requestError) { if (active) setError(boundedError(requestError)); })
                .finally(function () { if (active) setLoading(false); });
            return function () { active = false; };
        }, [videoId]);

        const publicationBackend = state && state.video && state.video.destination_valid && state.video.destination
            && state.video.destination.backend_id !== 'local'
            ? state.video.destination.backend_id : '';

        useEffect(function () {
            let active = true;
            setPublication(null);
            setPublicationDraft(null);
            setTagsDraftText('');
            setPublicationError('');
            setReplaceExisting(false);
            if (videoId < 1 || !publicationBackend) {
                setPublicationLoading(false);
                return function () { active = false; };
            }
            setPublicationLoading(true);
            apiFetch({ path: API_ROOT + '/' + videoId + '/publication' })
                .then(function (response) {
                    if (active) {
                        setPublication(response);
                        setPublicationDraft(clone(response.draft));
                        setTagsDraftText(tagsText(response.draft && response.draft.tags));
                    }
                })
                .catch(function (requestError) { if (active) setPublicationError(boundedError(requestError)); })
                .finally(function () { if (active) setPublicationLoading(false); });
            return function () { active = false; };
        }, [videoId, publicationBackend]);

        const editorialGate = editorialGateState(
            videoId, state, publication, publicationDraft, Number(editorPost.id || 0)
        );
        const publicationStatusProtected = protectedEditorStatus(editorPost.status);
        const editorialLockName = 'awvp-publication-review-' + String(props.clientId || videoId || 'video-block');

        useEffect(function () {
            const editorDispatch = wp.data.dispatch('core/editor');
            if (!editorDispatch || !editorDispatch.lockPostSaving || !editorDispatch.unlockPostSaving) {
                return function () {};
            }
            const shouldLock = publicationStatusProtected && editorialGate.applicable && !editorialGate.ready;
            if (shouldLock) {
                editorDispatch.lockPostSaving(editorialLockName);
            } else {
                editorDispatch.unlockPostSaving(editorialLockName);
            }
            return function () { editorDispatch.unlockPostSaving(editorialLockName); };
        }, [editorialLockName, publicationStatusProtected, editorialGate.applicable, editorialGate.ready]);

        function bindMedia(media) {
            const attachmentId = Number(media && media.id ? media.id : 0);
            const originPostId = Number(select('core/editor').getCurrentPostId() || 0);
            if (attachmentId < 1 || originPostId < 1) {
                setError(__('AWVP needs a saved WordPress post and a video attachment before it can bind this block.', 'argentwolf-video-processor'));
                return;
            }
            setSaving(true);
            setError('');
            apiFetch({
                path: API_ROOT,
                method: 'POST',
                data: { attachment_id: attachmentId, origin_post_id: originPostId }
            })
                .then(function (response) {
                    setState(response);
                    setAttributes({ videoId: Number(response.video.id) });
                })
                .catch(function (requestError) { setError(boundedError(requestError)); })
                .finally(function () { setSaving(false); });
        }

        function chooseDestination(value) {
            if (videoId < 1 || saving || value === '') return;
            let mode = value;
            let backendId = '';
            if (value.indexOf('backend:') === 0) {
                mode = 'backend';
                backendId = value.slice('backend:'.length);
            }
            setSaving(true);
            setError('');
            apiFetch({
                path: API_ROOT + '/' + videoId + '/destination',
                method: 'POST',
                data: { mode: mode, backend_id: backendId }
            })
                .then(function (response) { setState(response); })
                .catch(function (requestError) { setError(boundedError(requestError)); })
                .finally(function () { setSaving(false); });
        }

        function setDraftField(field, value, reviewField) {
            setPublicationDraft(function (previous) {
                const next = clone(previous) || {};
                next[field] = value;
                if (reviewField && next.review) next.review[reviewField] = false;
                return next;
            });
        }

        function setNested(group, field, value, reviewField) {
            setPublicationDraft(function (previous) {
                const next = clone(previous) || {};
                next[group] = next[group] || {};
                next[group][field] = value;
                if (reviewField && next.review) next.review[reviewField] = false;
                if ('moderation' === group && reviewField && next.moderation) next.moderation.reviewed = false;
                return next;
            });
        }

        function setAllReview(value) {
            setPublicationDraft(function (previous) {
                const next = clone(previous) || {};
                next.review = next.review || {};
                ['title', 'channel', 'tags', 'privacy', 'moderation'].forEach(function (field) {
                    next.review[field] = !!value;
                });
                next.moderation = next.moderation || {};
                next.moderation.reviewed = !!value;
                return next;
            });
        }

        function savePublication() {
            if (!publicationDraft || publicationSaving || !publication || !publication.catalog || !publication.catalog.usable) return;
            setPublicationSaving(true);
            setPublicationError('');
            apiFetch({
                path: API_ROOT + '/' + videoId + '/publication',
                method: 'POST',
                data: { plan: publicationDraft, replace_existing: !!replaceExisting }
            })
                .then(function (response) {
                    setPublication(response);
                    setPublicationDraft(clone(response.draft));
                    setTagsDraftText(tagsText(response.draft && response.draft.tags));
                    setReplaceExisting(false);
                    // The publication service also freezes the selected channel
                    // into the concrete destination; reload the summary label.
                    return apiFetch({ path: API_ROOT + '/' + videoId });
                })
                .then(function (response) { setState(response); })
                .catch(function (requestError) { setPublicationError(boundedError(requestError)); })
                .finally(function () { setPublicationSaving(false); });
        }

        function publicationPanel() {
            if (!publicationBackend) return null;
            if (publicationLoading) return el(PanelBody, { title: __('PeerTube publication', 'argentwolf-video-processor'), initialOpen: true }, el(Spinner));
            if (publicationError) return el(PanelBody, { title: __('PeerTube publication', 'argentwolf-video-processor'), initialOpen: true }, el(Notice, { status: 'error', isDismissible: false }, publicationError));
            if (!publication || !publicationDraft) return null;

            const choices = publication.choices || {};
            const catalog = publication.catalog || {};
            const moderationCaps = choices.moderation || {};
            const supportMode = publicationDraft.support ? publicationDraft.support.mode : 'none';
            const replaceRequired = publication.plan_status === 'backend_mismatch';
            const canSave = !!catalog.usable && (!replaceRequired || replaceExisting);
            const persistedDraft = publication.draft || null;
            const draftDirty = JSON.stringify(publicationDraft) !== JSON.stringify(persistedDraft);
            const review = publicationDraft.review || {};
            const moderation = publicationDraft.moderation || {};
            const requiredReview = ['title', 'channel', 'tags', 'privacy', 'moderation'];
            const draftMissingReview = requiredReview.filter(function (field) { return review[field] !== true; });
            if (moderation.reviewed !== true && draftMissingReview.indexOf('moderation') === -1) {
                draftMissingReview.push('moderation');
            }
            const persistedMissing = Array.isArray(publication.missing_review) ? publication.missing_review : [];
            const missing = (draftDirty ? draftMissingReview : persistedMissing).join(', ');
            const draftReady = !draftDirty && publication.ready_for_dispatch === true;
            const allReviewed = requiredReview.every(function (field) { return review[field] === true; })
                && moderation.reviewed === true;

            return el(PanelBody, { title: __('PeerTube publication', 'argentwolf-video-processor'), initialOpen: true },
                catalog.status === 'stale'
                    ? el(Notice, { status: 'warning', isDismissible: false }, __('Provider choices are from the last-known-good cache and are marked stale. Refresh them in Settings → AWVP Video Publishing when possible.', 'argentwolf-video-processor'))
                    : null,
                !catalog.usable
                    ? el(Notice, { status: 'error', isDismissible: false }, __('Publishing options have not been loaded from this PeerTube server yet. Go to Settings → ArgentWolf Video Processor → Publishing and load this server’s publishing options before configuring the video.', 'argentwolf-video-processor'))
                    : null,
                publication.plan_status === 'invalid'
                    ? el(Notice, { status: 'error', isDismissible: false }, __('A malformed or future publication plan already exists. AWVP will preserve it rather than overwrite it implicitly.', 'argentwolf-video-processor'))
                    : null,
                publication.plan_status === 'channel_mismatch'
                    ? el(Notice, { status: 'warning', isDismissible: false }, __('The concrete destination channel and stored publication-plan channel differ. AWVP preserved the stored channel but cleared channel review; review the channel choice before saving.', 'argentwolf-video-processor'))
                    : null,
                replaceRequired
                    ? el(Notice, { status: 'warning', isDismissible: false },
                        __('The stored publication plan belongs to a different PeerTube backend. Replacing it is explicit and discards that old editable plan.', 'argentwolf-video-processor'),
                        el(CheckboxControl, {
                            label: __('Replace the previous backend publication plan', 'argentwolf-video-processor'),
                            checked: replaceExisting,
                            onChange: setReplaceExisting
                        })
                    ) : null,
                el('p', null, __('Backend:', 'argentwolf-video-processor') + ' ' + publication.backend.label),
                el(TextControl, {
                    label: __('PeerTube title', 'argentwolf-video-processor'),
                    value: publicationDraft.title || '',
                    onChange: function (value) { setDraftField('title', value, 'title'); }
                }),
                el(TextareaControl, {
                    label: __('Markdown description', 'argentwolf-video-processor'),
                    value: publicationDraft.description_markdown || '',
                    rows: 5,
                    onChange: function (value) { setDraftField('description_markdown', value); }
                }),
                el(SelectControl, {
                    label: __('Channel', 'argentwolf-video-processor'),
                    value: publicationDraft.channel_id || '',
                    options: choiceOptions(choices.channels, __('Choose a channel…', 'argentwolf-video-processor')),
                    onChange: function (value) { setDraftField('channel_id', value, 'channel'); }
                }),
                el(TextareaControl, {
                    label: __('PeerTube tags — one per line, maximum five', 'argentwolf-video-processor'),
                    value: tagsDraftText,
                    rows: 5,
                    onChange: function (value) {
                        setTagsDraftText(value);
                        setDraftField('tags', parseTags(value), 'tags');
                    },
                    help: __('These are independent from WordPress post tags. Zero tags is valid only after you explicitly review the tag choice.', 'argentwolf-video-processor')
                }),
                el(SelectControl, {
                    label: __('Support', 'argentwolf-video-processor'),
                    value: supportMode,
                    options: [
                        { label: __('None', 'argentwolf-video-processor'), value: 'none' },
                        { label: __('Site preset', 'argentwolf-video-processor'), value: 'preset' },
                        { label: __('Custom Markdown', 'argentwolf-video-processor'), value: 'custom' }
                    ],
                    onChange: function (value) {
                        setPublicationDraft(function (previous) {
                            const next = clone(previous) || {};
                            next.support = value === 'preset'
                                ? { mode: 'preset', preset_id: '', markdown: '' }
                                : value === 'custom'
                                    ? { mode: 'custom', preset_id: '', markdown: '' }
                                    : { mode: 'none', preset_id: '', markdown: '' };
                            return next;
                        });
                    }
                }),
                supportMode === 'preset' ? el(SelectControl, {
                    label: __('Support preset', 'argentwolf-video-processor'),
                    value: publicationDraft.support.preset_id || '',
                    options: choiceOptions(choices.support_presets, __('Choose a preset…', 'argentwolf-video-processor')),
                    onChange: function (value) { setNested('support', 'preset_id', value); }
                }) : null,
                supportMode === 'custom' ? el(TextareaControl, {
                    label: __('Custom support Markdown', 'argentwolf-video-processor'),
                    value: publicationDraft.support.markdown || '',
                    rows: 4,
                    onChange: function (value) { setNested('support', 'markdown', value); }
                }) : null,
                el(SelectControl, {
                    label: __('Final privacy', 'argentwolf-video-processor'),
                    value: publicationDraft.final_privacy_id || '',
                    options: choiceOptions(choices.privacies, __('Choose privacy…', 'argentwolf-video-processor')),
                    onChange: function (value) { setDraftField('final_privacy_id', value, 'privacy'); }
                }),
                Array.isArray(choices.unsupported_privacies) && choices.unsupported_privacies.length
                    ? el('p', { className: 'description' }, __('PeerTube advertises additional privacy modes that AWVP does not yet author safely (for example password-protected video); they remain discoverable but are not selectable here.', 'argentwolf-video-processor'))
                    : null,
                el(SelectControl, {
                    label: __('Licence', 'argentwolf-video-processor'),
                    value: publicationDraft.licence_id || '',
                    options: choiceOptions(choices.licences, __('No licence selected', 'argentwolf-video-processor')),
                    onChange: function (value) { setDraftField('licence_id', value); }
                }),
                el(SelectControl, {
                    label: __('Category', 'argentwolf-video-processor'),
                    value: publicationDraft.category_id || '',
                    options: choiceOptions(choices.categories, __('No category selected', 'argentwolf-video-processor')),
                    onChange: function (value) { setDraftField('category_id', value); }
                }),
                el(SelectControl, {
                    label: __('Language', 'argentwolf-video-processor'),
                    value: publicationDraft.language || '',
                    options: choiceOptions(choices.languages, __('No language selected', 'argentwolf-video-processor')),
                    onChange: function (value) { setDraftField('language', value); }
                }),
                el(SelectControl, {
                    label: __('Comments', 'argentwolf-video-processor'),
                    value: publicationDraft.comments_policy || 'enabled',
                    options: choiceOptions(choices.comments),
                    onChange: function (value) { setDraftField('comments_policy', value); }
                }),
                el(ToggleControl, {
                    label: __('Allow downloads', 'argentwolf-video-processor'),
                    checked: !!publicationDraft.download_enabled,
                    onChange: function (value) { setDraftField('download_enabled', !!value); }
                }),
                el(TextControl, {
                    label: __('Originally published at (optional UTC)', 'argentwolf-video-processor'),
                    value: publicationDraft.originally_published_at || '',
                    placeholder: '2026-09-06T20:00:00Z',
                    onChange: function (value) { setDraftField('originally_published_at', value); }
                }),
                el(MediaUploadCheck, null,
                    el(MediaUpload, {
                        allowedTypes: ['image'],
                        multiple: false,
                        onSelect: function (media) { setDraftField('thumbnail_attachment_id', Number(media && media.id ? media.id : 0)); },
                        render: function (media) {
                            return el('div', null,
                                el(Button, { variant: 'secondary', onClick: media.open }, publicationDraft.thumbnail_attachment_id
                                    ? __('Replace thumbnail', 'argentwolf-video-processor')
                                    : __('Choose thumbnail', 'argentwolf-video-processor')),
                                publicationDraft.thumbnail_attachment_id
                                    ? el(Button, { variant: 'tertiary', onClick: function () { setDraftField('thumbnail_attachment_id', 0); } }, __('Clear', 'argentwolf-video-processor'))
                                    : null,
                                publicationDraft.thumbnail_attachment_id
                                    ? el('span', { style: { marginLeft: '0.5rem' } }, '#' + publicationDraft.thumbnail_attachment_id)
                                    : null
                            );
                        }
                    })
                ),
                moderationCaps.sensitive_content ? el(ToggleControl, {
                    label: __('Sensitive content', 'argentwolf-video-processor'),
                    checked: !!(publicationDraft.moderation && publicationDraft.moderation.sensitive),
                    onChange: function (value) {
                        setPublicationDraft(function (previous) {
                            const next = clone(previous) || {};
                            next.moderation = next.moderation || {};
                            next.review = next.review || {};
                            next.moderation.sensitive = !!value;
                            if (!value) {
                                next.moderation.reason = '';
                                next.moderation.violent = false;
                                next.moderation.sexually_explicit = false;
                            }
                            next.moderation.reviewed = false;
                            next.review.moderation = false;
                            return next;
                        });
                    }
                }) : null,
                publicationDraft.moderation && publicationDraft.moderation.sensitive ? el(TextControl, {
                    label: __('Sensitive-content summary', 'argentwolf-video-processor'),
                    value: publicationDraft.moderation.reason || '',
                    onChange: function (value) { setNested('moderation', 'reason', value, 'moderation'); }
                }) : null,
                moderationCaps.sensitive_flags && publicationDraft.moderation && publicationDraft.moderation.sensitive
                    ? el('div', null,
                        el(CheckboxControl, {
                            label: __('Violent', 'argentwolf-video-processor'),
                            checked: !!publicationDraft.moderation.violent,
                            onChange: function (value) { setNested('moderation', 'violent', !!value, 'moderation'); }
                        }),
                        el(CheckboxControl, {
                            label: __('Sexually explicit', 'argentwolf-video-processor'),
                            checked: !!publicationDraft.moderation.sexually_explicit,
                            onChange: function (value) { setNested('moderation', 'sexually_explicit', !!value, 'moderation'); }
                        })
                    ) : null,
                el('hr'),
                el('strong', null, __('Explicit review', 'argentwolf-video-processor')),
                el(CheckboxControl, {
                    label: __('I reviewed these PeerTube publishing settings', 'argentwolf-video-processor'),
                    checked: allReviewed,
                    onChange: setAllReview,
                    help: __('Confirms the title, channel, tags, final privacy, and sensitive-content declaration shown above. Changing any reviewed item requires review again.', 'argentwolf-video-processor')
                }),
                el(SelectControl, {
                    label: __('Dispatch timing', 'argentwolf-video-processor'),
                    value: publicationDraft.dispatch_policy || 'send_on_schedule_or_publish',
                    options: [
                        { label: __('Send now', 'argentwolf-video-processor'), value: 'send_now' },
                        { label: __('Send when scheduled or published', 'argentwolf-video-processor'), value: 'send_on_schedule_or_publish' }
                    ],
                    onChange: function (value) { setDraftField('dispatch_policy', value); },
                    help: __('Saving this choice does not start a PeerTube upload.', 'argentwolf-video-processor')
                }),
                draftReady
                    ? el(Notice, { status: 'success', isDismissible: false }, __('All required publication decisions are reviewed. WordPress publication may proceed; no remote work has started.', 'argentwolf-video-processor'))
                    : el(Notice, { status: 'info', isDismissible: false }, missing
                        ? __('Needs review:', 'argentwolf-video-processor') + ' ' + missing
                        : draftDirty
                            ? __('This draft has unsaved changes. Save it to validate the complete publication plan; no remote work will start.', 'argentwolf-video-processor')
                            : __('Save the plan to evaluate its review state.', 'argentwolf-video-processor')),
                publicationError ? el(Notice, { status: 'error', isDismissible: false }, publicationError) : null,
                el(Button, {
                    variant: 'primary',
                    disabled: publicationSaving || !canSave || publication.plan_status === 'invalid',
                    onClick: savePublication
                }, publicationSaving ? __('Saving publication plan…', 'argentwolf-video-processor') : __('Save PeerTube publication plan', 'argentwolf-video-processor'))
            );
        }

        if (videoId < 1) {
            return el('div', blockProps,
                error ? el(Notice, { status: 'error', isDismissible: false }, error) : null,
                el('p', null, __('Choose or upload a WordPress video. AWVP will bind the attachment to one durable AWVP Video and resolve the site destination default once for this new video.', 'argentwolf-video-processor')),
                el(MediaUploadCheck, null,
                    el(MediaUpload, {
                        allowedTypes: ['video'], multiple: false, onSelect: bindMedia,
                        render: function (media) {
                            return el(Button, { variant: 'primary', onClick: media.open, disabled: saving },
                                saving ? __('Binding video…', 'argentwolf-video-processor') : __('Choose WordPress video', 'argentwolf-video-processor'));
                        }
                    })
                )
            );
        }

        const options = destinationOptions(state);
        const current = destinationValue(state);
        return el('div', blockProps,
            editorialGate.applicable && !editorialGate.ready
                ? el(Notice, { status: 'warning', isDismissible: false },
                    __('WordPress publication is blocked until AWVP editorial review is complete. Draft saving remains allowed. ', 'argentwolf-video-processor') + editorialGate.detail)
                : null,
            el(InspectorControls, null,
                el(PanelBody, { title: __('AWVP destination', 'argentwolf-video-processor'), initialOpen: true },
                    state && !state.video.destination_valid
                        ? el(Notice, { status: 'warning', isDismissible: false }, __('The stored destination is invalid. Choose an explicit destination to repair it.', 'argentwolf-video-processor')) : null,
                    el(SelectControl, {
                        label: __('Final destination', 'argentwolf-video-processor'), value: current, options: options,
                        disabled: loading || saving || options.length === 0, onChange: chooseDestination,
                        help: __('Destination selection remains planning state. Local WordPress playback stays authoritative until a later verified cutover.', 'argentwolf-video-processor')
                    })
                ),
                publicationPanel()
            ),
            error ? el(Notice, { status: 'error', isDismissible: false }, error) : null,
            loading ? el(Spinner) : null,
            state && state.video
                ? el('div', null,
                    el('strong', null, state.video.attachment_title || __('WordPress video', 'argentwolf-video-processor')),
                    state.video.attachment_url ? el('video', { controls: true, preload: 'metadata', src: state.video.attachment_url, style: { width: '100%', marginTop: '0.75rem' } }) : null,
                    el('p', null, state.video.destination_valid && state.video.destination
                        ? __('Destination:', 'argentwolf-video-processor') + ' ' + state.video.destination.label
                        : __('Destination needs review.', 'argentwolf-video-processor'))
                ) : null
        );
    }

    registerBlockType('argentwolf-video-processor/video', {
        title: __('ArgentWolf Video', 'argentwolf-video-processor'),
        description: __('Bind a WordPress video to an AWVP Video, choose its destination, and review PeerTube publication intent.', 'argentwolf-video-processor'),
        category: 'media', icon: 'video-alt3',
        attributes: { videoId: { type: 'integer', default: 0 } },
        edit: Edit,
        save: function () { return null; }
    });
}(window.wp));
