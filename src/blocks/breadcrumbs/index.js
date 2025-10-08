import { registerBlockType } from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';
import {
	AlignmentControl,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Fragment,
	createElement,
	useEffect,
	useMemo,
} from '@wordpress/element';
import {
	PanelBody,
	ToggleControl,
	TextControl,
} from '@wordpress/components';
import './style.scss';
import './editor.scss';

const useEditorRuntimeInfo = () =>
	useMemo(() => {
		if (typeof window === 'undefined') {
			return {};
		}

		const search = window.location?.search || '';
		const params = new URLSearchParams(search);
		const urlParams = {};
		params.forEach((value, key) => {
			urlParams[key] = value;
		});

		return {
			urlParams,
			urlPath: urlParams.p || '',
			locationPath: window.location?.pathname || '',
		};
	}, []);

const sanitizeSeparator = (separator = '/') => {
	const trimmed = (separator || '').toString().trim();
	if (!trimmed) {
		return '/';
	}
	return trimmed.substring(0, 10);
};

const humanize = (value, fallback) => {
	if (!value || typeof value !== 'string') {
		return fallback;
	}

	const formatted = value.replace(/[-_]/g, ' ').trim();
	if (!formatted) {
		return fallback;
	}

	return formatted.replace(/\b\w/g, (char) => char.toUpperCase());
};

const pickFirst = (value) => (Array.isArray(value) ? value[0] : value);

const isSingleTemplateContext = (context = {}) => {
	const slug = context.templateSlug || '';
	const type = context.templateType || '';

	if (type === 'single') {
		return true;
	}

	if (slug) {
		return slug.startsWith('single');
	}

	return false;
};

const getPreviewScenario = (context = {}) => {
	const {
		postId,
		postType,
		termId,
		taxonomy,
		termType,
		previewTerms = [],
		query = {},
	} = context;

	const firstPreviewTerm = previewTerms[0];
	const normalizedTaxonomy =
		termType || taxonomy || firstPreviewTerm?.taxonomy || firstPreviewTerm?.type;
	const templateSlug = context.templateSlug || '';
	const templateType = context.templateType || '';
	const runtimeInfo = context.runtimeInfo || {};
	const urlParams = runtimeInfo.urlParams || {};
	const rawPath =
		runtimeInfo.urlPath ||
		urlParams.p ||
		context.previewPath ||
		'';
	const normalizedPath = decodeURIComponent(rawPath);
	const urlSegments = normalizedPath
		.split('//')
		.concat(normalizedPath.split('/'))
		.filter(Boolean);
	const keywordCandidates = new Set(
		[
			templateSlug,
			templateType,
			normalizedTaxonomy,
			urlParams.wp_template,
			urlParams.template,
			urlParams.c,
			...urlSegments,
		]
			.filter(Boolean)
			.map((value) => value.toString().toLowerCase())
	);

 if (runtimeInfo.locationPath) {
		runtimeInfo.locationPath
			.split('/')
			.filter(Boolean)
			.forEach((segment) => {
				keywordCandidates.add(segment.toLowerCase());
			});
	}

	const matchesKeyword = (keyword) => {
		if (!keyword) {
			return false;
		}
		const target = keyword.toLowerCase();
		for (const candidate of keywordCandidates) {
			if (!candidate) {
				continue;
			}
			if (candidate === target) {
				return true;
			}
			if (candidate.startsWith(`${target}-`)) {
				return true;
			}
			if (candidate.endsWith(`-${target}`)) {
				return true;
			}
		}
		return false;
	};

	const extractPrefixed = (prefix) => {
		const target = prefix.toLowerCase();
		for (const candidate of keywordCandidates) {
			if (candidate && candidate.startsWith(`${target}-`)) {
				return candidate.slice(target.length + 1);
			}
		}
		return undefined;
	};

	if (postId) {
		if (postType === 'page') {
			return { type: 'page', title: context.title };
		}

		if (postType && postType !== 'post') {
			return {
				type: 'customSingle',
				postType,
				title: context.title,
			};
		}

		return {
			type: 'post',
			title: context.title,
		};
	}

	if (!postId) {
		const taxonomyHint = extractPrefixed('taxonomy');

		if (matchesKeyword('search')) {
			return {
				type: 'search',
				searchTerm: '',
				title: context.title,
			};
		}

		if (matchesKeyword('author')) {
			return {
				type: 'author',
				authorName: context.title,
			};
		}

		if (taxonomyHint) {
			return {
				type: 'term',
				taxonomy: taxonomyHint,
				termLabel: context.title,
			};
		}

		if (matchesKeyword('post_tag') || matchesKeyword('tag')) {
			return {
				type: 'term',
				taxonomy: 'post_tag',
				termLabel: context.title,
			};
		}

		if (matchesKeyword('category')) {
			return {
				type: 'term',
				taxonomy: 'category',
				termLabel: context.title,
			};
		}

		if (matchesKeyword('archive')) {
			return {
				type: 'postTypeArchive',
				postType: pickFirst(query.postType || postType) || 'post',
				title: context.title,
			};
		}
	}

	if (termId || normalizedTaxonomy || firstPreviewTerm) {
		return {
			type: 'term',
			taxonomy: normalizedTaxonomy,
			termLabel: firstPreviewTerm?.name,
		};
	}

	if (query.search) {
		return { type: 'search', searchTerm: query.search };
	}

	if (query.author || context.authorId) {
		return {
			type: 'author',
			authorName: context.authorName || query.authorName || '',
		};
	}

	const archivePostType = pickFirst(query.postType || postType);

	if (!postId && archivePostType && isSingleTemplateContext(context)) {
		return {
			type: 'singleTemplate',
			postType: archivePostType,
			title: context.title,
		};
	}

	if (archivePostType && !query.inherit) {
		return { type: 'postTypeArchive', postType: archivePostType };
	}

	if (query.year || query.monthnum || query.day) {
		return {
			type: 'date',
			year: query.year,
			month: query.monthnum,
			day: query.day,
		};
	}

	if (context.is404) {
		return { type: 'notFound' };
	}

	return { type: 'default' };
};

const taxonomyPlaceholders = {
	category: {
		archive: __('Category archive', 'ace-crawl-enhancer'),
		term: __('Category Title', 'ace-crawl-enhancer'),
	},
	post_tag: {
		archive: __('Tag archive', 'ace-crawl-enhancer'),
		term: __('Tag Title', 'ace-crawl-enhancer'),
	},
};

const buildPreviewItems = (attributes, context = {}) => {
	const { showHome, showCurrent } = attributes;
	const scenario = getPreviewScenario(context);

	const items = [];
	const addCrumb = (label, { isCurrent = false } = {}) => {
		if (!label) {
			return;
		}

		items.push({
			label,
			isCurrent,
			isLink: !isCurrent,
		});
	};

	if (showHome) {
		addCrumb(__('Home', 'ace-crawl-enhancer'));
	}

	switch (scenario.type) {
		case 'post': {
			const postTitle =
				scenario.title ||
				context?.title ||
				__('Sample Post Title', 'ace-crawl-enhancer');
			addCrumb(__('Post archive', 'ace-crawl-enhancer'));
			addCrumb(postTitle, { isCurrent: true });
			break;
		}
		case 'page': {
			const pageTitle =
				scenario.title ||
				context?.title ||
				__('Sample Page Title', 'ace-crawl-enhancer');
			addCrumb(__('Parent Page', 'ace-crawl-enhancer'));
			addCrumb(pageTitle, { isCurrent: true });
			break;
		}
		case 'customSingle': {
			const archiveLabel = humanize(
				scenario.postType,
				__('Archive', 'ace-crawl-enhancer')
			);
			const itemTitle =
				scenario.title ||
				context?.title ||
				__('Sample Item Title', 'ace-crawl-enhancer');
			addCrumb(archiveLabel || __('Archive', 'ace-crawl-enhancer'));
			addCrumb(itemTitle, { isCurrent: true });
			break;
		}
		case 'singleTemplate': {
			const archiveLabel = humanize(
				scenario.postType,
				__('Archive', 'ace-crawl-enhancer')
			);
			const postTitle =
				scenario.title ||
				context?.title ||
				__('Sample Post Title', 'ace-crawl-enhancer');

			addCrumb(archiveLabel || __('Post archive', 'ace-crawl-enhancer'));
			addCrumb(postTitle, { isCurrent: true });
			break;
		}
		case 'term': {
			const taxonomyPlaceholder =
				taxonomyPlaceholders[scenario.taxonomy] || {};
			const termLabel =
				scenario.termLabel
				|| taxonomyPlaceholder.term
				|| context?.title
				|| humanize(scenario.taxonomy, __('Term Title', 'ace-crawl-enhancer'));

			addCrumb(
				termLabel,
				{ isCurrent: true }
			);
			break;
		}
		case 'search': {
			const searchLabel = scenario.searchTerm
				? sprintf(
						/* translators: %s: search term. */
						__('Search results for “%s”', 'ace-crawl-enhancer'),
						scenario.searchTerm
				  )
				: context?.title || __('Search results', 'ace-crawl-enhancer');
			addCrumb(searchLabel, { isCurrent: true });
			break;
		}
		case 'author': {
			const authorName =
				scenario.authorName?.trim() ||
				context?.title ||
				__('Author Name', 'ace-crawl-enhancer');
			addCrumb(
				sprintf(
					/* translators: %s: author name. */
					__('Articles by %s', 'ace-crawl-enhancer'),
					authorName
				),
				{ isCurrent: true }
			);
			break;
		}
		case 'postTypeArchive': {
			const archiveLabel =
				scenario.title ||
				context?.title ||
				(scenario.postType === 'post'
					? __('Post archive', 'ace-crawl-enhancer')
					: sprintf(
							/* translators: %s: post type label. */
							__('%s archive', 'ace-crawl-enhancer'),
							humanize(
								scenario.postType,
								__('Content', 'ace-crawl-enhancer')
							)
					  ));

			addCrumb(archiveLabel, { isCurrent: true });
			break;
		}
		case 'date': {
			const { year, month, day } = scenario;
			let label = __('Date archive', 'ace-crawl-enhancer');
			if (year || month || day) {
				const parts = [];
				if (month) {
					const date = new Date(2000, Number(month) - 1, 1);
					const monthName = date.toLocaleString(undefined, {
						month: 'long',
					});
					if (monthName) {
						parts.push(monthName);
					}
				}
				if (day) {
					parts.push(String(day));
				}
				if (year) {
					parts.push(String(year));
				}
				if (parts.length) {
					label = sprintf(
						/* translators: %s: formatted date string. */
						__('Archive: %s', 'ace-crawl-enhancer'),
						parts.join(' ')
					);
				}
			}
			addCrumb(label, { isCurrent: true });
			break;
		}
		case 'notFound': {
			addCrumb(__('Page not found', 'ace-crawl-enhancer'), { isCurrent: true });
			break;
		}
		default: {
			addCrumb(__('Post archive', 'ace-crawl-enhancer'), { isCurrent: true });
		}
	}

	if (!showCurrent && items.length) {
		items.pop();
	}

	if (!items.length) {
		addCrumb(__('Current Item', 'ace-crawl-enhancer'), { isCurrent: true });
	}

	return items;
};

registerBlockType('ace-seo/breadcrumbs', {
	title: __('ACE SEO Breadcrumbs', 'ace-crawl-enhancer'),
	description: __('Displays breadcrumb navigation generated by ACE SEO.', 'ace-crawl-enhancer'),
edit({ attributes, setAttributes, context }) {
		const {
			textAlign,
			showHome,
			showCurrent,
			showLabel,
			labelText,
			separator,
			ariaLabel,
		} = attributes;

		const runtimeInfo = useEditorRuntimeInfo();
		const mergedContext = useMemo(
			() => ({
				...context,
				runtimeInfo,
			}),
			[context, runtimeInfo]
		);

		useEffect(() => {
			if (typeof window !== 'undefined' && window.console) {
				window.console.debug('[ACE Breadcrumbs] preview context', mergedContext);
			}
		}, [mergedContext]);

		const items = useMemo(
			() => buildPreviewItems(attributes, mergedContext),
			[attributes, mergedContext]
		);

		const sanitizedSeparator = sanitizeSeparator(separator);
		const labelContent = showLabel
			? (labelText || __('You are here:', 'ace-crawl-enhancer')).trim()
			: '';

		const blockProps = useBlockProps({
			className: [
				'ace-seo-breadcrumbs',
				'ace-seo-breadcrumbs-editor',
				textAlign ? `has-text-align-${textAlign}` : null,
			]
				.filter(Boolean)
				.join(' '),
			style: textAlign ? { textAlign } : undefined,
			'aria-label': ariaLabel || __('Breadcrumbs', 'ace-crawl-enhancer'),
		});

		return createElement(
			Fragment,
			null,
			createElement(
				InspectorControls,
				null,
				createElement(
					PanelBody,
					{
						title: __('Display Options', 'ace-crawl-enhancer'),
						initialOpen: true,
					},
					createElement(ToggleControl, {
						label: __('Show “Home” breadcrumb', 'ace-crawl-enhancer'),
						checked: showHome,
						onChange: (value) => setAttributes({ showHome: value }),
					}),
					createElement(ToggleControl, {
						label: __('Show current page/item', 'ace-crawl-enhancer'),
						checked: showCurrent,
						onChange: (value) => setAttributes({ showCurrent: value }),
					}),
					createElement(ToggleControl, {
						label: __('Show label before breadcrumbs', 'ace-crawl-enhancer'),
						checked: showLabel,
						onChange: (value) => setAttributes({ showLabel: value }),
					}),
					showLabel
						? createElement(TextControl, {
								label: __('Label text', 'ace-crawl-enhancer'),
								value: labelText,
								onChange: (value) => setAttributes({ labelText: value }),
						  })
						: null
				),
				createElement(
					PanelBody,
					{
						title: __('Separator & Accessibility', 'ace-crawl-enhancer'),
						initialOpen: false,
					},
					createElement(TextControl, {
						label: __('Separator', 'ace-crawl-enhancer'),
						help: __('Displayed between breadcrumb items.', 'ace-crawl-enhancer'),
						value: separator,
						onChange: (value) => setAttributes({ separator: value }),
						maxLength: 10,
					}),
					createElement(TextControl, {
						label: __('ARIA label', 'ace-crawl-enhancer'),
						help: __('Accessible name announced for the breadcrumb navigation.', 'ace-crawl-enhancer'),
						value: ariaLabel,
						onChange: (value) => setAttributes({ ariaLabel: value }),
					}),
					createElement(AlignmentControl, {
						label: __('Text alignment', 'ace-crawl-enhancer'),
						value: textAlign,
						onChange: (value) => setAttributes({ textAlign: value || '' }),
					})
				)
			),
			createElement(
				'nav',
				blockProps,
				labelContent
					? createElement(
							'span',
							{ className: 'ace-seo-breadcrumbs__label' },
							labelContent
					  )
					: null,
				createElement(
					'ol',
					{
						className: 'ace-seo-breadcrumbs__list',
						itemScope: true,
						itemType: 'https://schema.org/BreadcrumbList',
					},
					items.map((item, index) =>
						createElement(
							'li',
							{
								className: 'ace-seo-breadcrumbs__item',
								key: `${index}-${item.label}`,
								itemProp: 'itemListElement',
								itemScope: true,
								itemType: 'https://schema.org/ListItem',
							},
							index > 0
								? createElement(
										'span',
										{
											className: 'ace-seo-breadcrumbs__separator',
											'aria-hidden': 'true',
										},
										sanitizedSeparator
								  )
								: null,
							item.isCurrent || !item.isLink
								? createElement(
										'span',
										{
											className: 'ace-seo-breadcrumbs__current',
											itemProp: 'name',
											'aria-current': item.isCurrent ? 'page' : undefined,
										},
										item.label
								  )
								: createElement(
										'span',
										{
											className: 'ace-seo-breadcrumbs__link',
											itemProp: 'item',
										},
										createElement(
											'span',
											{ itemProp: 'name' },
											item.label
										)
								  ),
							createElement('meta', {
								itemProp: 'position',
								content: index + 1,
							})
						)
					)
				)
			)
		);
	},
	save() {
		return null;
	},
});
