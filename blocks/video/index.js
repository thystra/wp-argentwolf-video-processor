(function (wp) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element || !wp.components || !wp.blockEditor || !wp.apiFetch || !wp.data) {
        return;
    }

    const { registerBlockType } = wp.blocks;
    const { createElement: el, useEffect, useState } = wp.element;
    const { Button, Notice, PanelBody, SelectControl, Spinner } = wp.components;
    const { InspectorControls, MediaUpload, MediaUploadCheck, useBlockProps } = wp.blockEditor;
    const { __ } = wp.i18n;
    const { select } = wp.data;
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
                    value: destination.backend_id === 'local'
                        ? 'local'
                        : 'backend:' + destination.backend_id
                });
            });
        }
        return options;
    }

    function Edit(props) {
        const { attributes, setAttributes } = props;
        const videoId = Number(attributes.videoId || 0);
        const blockProps = useBlockProps();
        const [state, setState] = useState(null);
        const [loading, setLoading] = useState(videoId > 0);
        const [saving, setSaving] = useState(false);
        const [error, setError] = useState('');

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
                .then(function (response) {
                    if (active) {
                        setState(response);
                    }
                })
                .catch(function (requestError) {
                    if (active) {
                        setError(boundedError(requestError));
                    }
                })
                .finally(function () {
                    if (active) {
                        setLoading(false);
                    }
                });
            return function () { active = false; };
        }, [videoId]);

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
                data: {
                    attachment_id: attachmentId,
                    origin_post_id: originPostId
                }
            })
                .then(function (response) {
                    setState(response);
                    setAttributes({ videoId: Number(response.video.id) });
                })
                .catch(function (requestError) {
                    setError(boundedError(requestError));
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function chooseDestination(value) {
            if (videoId < 1 || saving || value === '') {
                return;
            }
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
                data: {
                    mode: mode,
                    backend_id: backendId
                }
            })
                .then(function (response) {
                    setState(response);
                })
                .catch(function (requestError) {
                    setError(boundedError(requestError));
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        if (videoId < 1) {
            return el('div', blockProps,
                error ? el(Notice, { status: 'error', isDismissible: false }, error) : null,
                el('p', null, __('Choose or upload a WordPress video. AWVP will bind the attachment to one durable AWVP Video and resolve the site destination default once for this new video.', 'argentwolf-video-processor')),
                el(MediaUploadCheck, null,
                    el(MediaUpload, {
                        allowedTypes: ['video'],
                        multiple: false,
                        onSelect: bindMedia,
                        render: function (media) {
                            return el(Button, {
                                variant: 'primary',
                                onClick: media.open,
                                disabled: saving
                            }, saving ? __('Binding video…', 'argentwolf-video-processor') : __('Choose WordPress video', 'argentwolf-video-processor'));
                        }
                    })
                )
            );
        }

        const options = destinationOptions(state);
        const current = destinationValue(state);
        return el('div', blockProps,
            el(InspectorControls, null,
                el(PanelBody, {
                    title: __('AWVP destination', 'argentwolf-video-processor'),
                    initialOpen: true
                },
                    state && !state.video.destination_valid
                        ? el(Notice, { status: 'warning', isDismissible: false }, __('The stored destination is invalid. Choose an explicit destination to repair it.', 'argentwolf-video-processor'))
                        : null,
                    el(SelectControl, {
                        label: __('Final destination', 'argentwolf-video-processor'),
                        value: current,
                        options: options,
                        disabled: loading || saving || options.length === 0,
                        onChange: chooseDestination,
                        help: __('Selecting a PeerTube backend only stores the final destination in R46.3b. Local WordPress playback remains authoritative and no PeerTube upload is started.', 'argentwolf-video-processor')
                    })
                )
            ),
            error ? el(Notice, { status: 'error', isDismissible: false }, error) : null,
            loading ? el(Spinner) : null,
            state && state.video
                ? el('div', null,
                    el('strong', null, state.video.attachment_title || __('WordPress video', 'argentwolf-video-processor')),
                    state.video.attachment_url
                        ? el('video', {
                            controls: true,
                            preload: 'metadata',
                            src: state.video.attachment_url,
                            style: { width: '100%', marginTop: '0.75rem' }
                        })
                        : null,
                    el('p', null,
                        state.video.destination_valid && state.video.destination
                            ? __('Destination:', 'argentwolf-video-processor') + ' ' + state.video.destination.label
                            : __('Destination needs review.', 'argentwolf-video-processor')
                    )
                )
                : null
        );
    }

    registerBlockType('argentwolf-video-processor/video', {
        title: __('ArgentWolf Video', 'argentwolf-video-processor'),
        description: __('Bind a WordPress video to an AWVP Video and choose its final destination.', 'argentwolf-video-processor'),
        category: 'media',
        icon: 'video-alt3',
        attributes: {
            videoId: { type: 'integer', default: 0 }
        },
        edit: Edit,
        save: function () { return null; }
    });
}(window.wp));
