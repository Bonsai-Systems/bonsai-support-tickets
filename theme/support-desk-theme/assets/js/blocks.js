/**
 * Support Desk theme — block editor.
 *
 * Registers every Support Desk block from the definitions in inc/blocks.php
 * (window.bsupBlocks) and builds each block's sidebar from its "fields".
 * The canvas shows a live server-rendered preview, except the Content
 * block, which holds normal editor blocks.
 *
 * Plain JS against the wp.* globals, so there's no build step. (The block
 * editor is React-based, so this file can't use jQuery like main.js.)
 */
(function (wp, data) {
	'use strict';

	if (!wp || !wp.blocks || !data) {
		return;
	}

	var el              = wp.element.createElement;
	var Fragment        = wp.element.Fragment;
	var registerBlock   = wp.blocks.registerBlockType;
	var be              = wp.blockEditor;
	var c               = wp.components;
	var ServerSideRender = wp.serverSideRender;
	var i18n            = data.i18n || {};

	/**
	 * Whether a field should show, given its show_when rule.
	 *
	 * @param {Object} field  Field definition.
	 * @param {Object} values Current values (block attributes or repeater item).
	 * @return {boolean}
	 */
	function bsup_visible(field, values) {
		if (!field.show_when) {
			return true;
		}
		return String(values[field.show_when[0]]) === String(field.show_when[1]);
	}

	/**
	 * Select options as [{label, value}].
	 *
	 * @param {Object} options value => label.
	 * @return {Array}
	 */
	function bsup_options(options) {
		return Object.keys(options || {}).map(function (value) {
			return { label: options[value], value: value };
		});
	}

	/**
	 * Move an array item.
	 *
	 * @param {Array}  list Items.
	 * @param {number} from Index.
	 * @param {number} to   Index.
	 * @return {Array} New array.
	 */
	function bsup_move(list, from, to) {
		var copy = list.slice();
		var item = copy.splice(from, 1)[0];
		copy.splice(to, 0, item);
		return copy;
	}

	/**
	 * Link control: text, URL, new tab.
	 */
	function bsup_link_control(field, value, onChange) {
		var link = value && typeof value === 'object' ? value : {};

		function set(key, val) {
			var next = Object.assign({}, link);
			next[key] = val;
			onChange(next);
		}

		return el(
			c.BaseControl,
			{ label: field.label, help: field.help, __nextHasNoMarginBottom: true },
			el(c.TextControl, {
				label: i18n.text,
				value: link.title || '',
				onChange: function (val) { set('title', val); },
				__nextHasNoMarginBottom: true
			}),
			el(c.TextControl, {
				label: i18n.url,
				type: 'url',
				value: link.url || '',
				onChange: function (val) { set('url', val); },
				__nextHasNoMarginBottom: true
			}),
			el(c.ToggleControl, {
				label: i18n.newTab,
				checked: '_blank' === link.target,
				onChange: function (on) { set('target', on ? '_blank' : ''); },
				__nextHasNoMarginBottom: true
			})
		);
	}

	/**
	 * Posts or terms picker: checkboxes, kept in the order they were ticked.
	 */
	function bsup_picker_control(field, value, onChange) {
		var chosen  = Array.isArray(value) ? value.map(Number) : [];
		var options = (data.options && data.options[field.source]) || [];

		if (!options.length) {
			return el(c.BaseControl, { label: field.label, help: i18n.none, __nextHasNoMarginBottom: true });
		}

		return el(
			c.BaseControl,
			{ label: field.label, help: field.help, __nextHasNoMarginBottom: true },
			el(
				'div',
				{ className: 'bsup-picker' },
				options.map(function (option) {
					var on       = chosen.indexOf(option.id) !== -1;
					var disabled = !on && field.max && chosen.length >= field.max;
					return el(c.CheckboxControl, {
						key: option.id,
						label: option.label,
						checked: on,
						disabled: !!disabled,
						onChange: function (checked) {
							onChange(checked ? chosen.concat([option.id]) : chosen.filter(function (id) { return id !== option.id; }));
						},
						__nextHasNoMarginBottom: true
					});
				})
			)
		);
	}

	/**
	 * Repeater: one collapsible panel per item, with move/remove buttons.
	 */
	function bsup_repeater_control(field, value, onChange) {
		var items = Array.isArray(value) ? value : [];
		var max   = field.max || 0;

		function update(index, key, val) {
			var next = items.slice();
			next[index] = Object.assign({}, next[index]);
			next[index][key] = val;
			onChange(next);
		}

		function add() {
			var item = {};
			field.fields.forEach(function (sub) {
				if (undefined !== sub['default']) {
					item[sub.name] = sub['default'];
				}
			});
			onChange(items.concat([item]));
		}

		return el(
			c.BaseControl,
			{ label: field.label, help: field.help, __nextHasNoMarginBottom: true },
			items.map(function (item, index) {
				var title = (field.title_key && item[field.title_key]) || (i18n.item + ' ' + (index + 1));
				return el(
					c.PanelBody,
					{ key: index, title: title, initialOpen: false, className: 'bsup-repeater-item' },
					field.fields.map(function (sub) {
						if (!bsup_visible(sub, item)) {
							return null;
						}
						return el(Fragment, { key: sub.name }, bsup_field(sub, item[sub.name], function (val) {
							update(index, sub.name, val);
						}));
					}),
					el(
						c.Flex,
						{ justify: 'flex-start', gap: 1 },
						el(c.Button, {
							variant: 'secondary',
							size: 'small',
							disabled: 0 === index,
							onClick: function () { onChange(bsup_move(items, index, index - 1)); }
						}, i18n.moveUp),
						el(c.Button, {
							variant: 'secondary',
							size: 'small',
							disabled: index === items.length - 1,
							onClick: function () { onChange(bsup_move(items, index, index + 1)); }
						}, i18n.moveDown),
						el(c.Button, {
							variant: 'tertiary',
							size: 'small',
							isDestructive: true,
							onClick: function () { onChange(items.filter(function (x, i) { return i !== index; })); }
						}, i18n.remove)
					)
				);
			}),
			(!max || items.length < max) ? el(c.Button, { variant: 'secondary', onClick: add }, field.add_label || '+') : null
		);
	}

	/**
	 * One sidebar control.
	 *
	 * @param {Object}   field    Field definition.
	 * @param {*}        value    Current value.
	 * @param {Function} onChange Setter.
	 */
	function bsup_field(field, value, onChange) {
		var common = { label: field.label, help: field.help, __nextHasNoMarginBottom: true };

		switch (field.type) {
			case 'textarea':
				return el(c.TextareaControl, Object.assign({}, common, { value: value || '', onChange: onChange }));
			case 'html':
				return el(c.TextareaControl, Object.assign({}, common, {
					help: field.help ? field.help + ' ' + i18n.htmlHelp : i18n.htmlHelp,
					value: value || '',
					rows: 5,
					onChange: onChange
				}));
			case 'select':
				return el(c.SelectControl, Object.assign({}, common, {
					value: undefined === value ? field['default'] : String(value),
					options: bsup_options(field.options),
					onChange: onChange
				}));
			case 'toggle':
				return el(c.ToggleControl, Object.assign({}, common, { checked: !!value, onChange: onChange }));
			case 'number':
				return el(c.TextControl, Object.assign({}, common, {
					type: 'number',
					min: field.min,
					max: field.max,
					value: undefined === value ? '' : value,
					onChange: function (val) { onChange('' === val ? undefined : parseInt(val, 10)); }
				}));
			case 'link':
				return bsup_link_control(field, value, onChange);
			case 'posts':
			case 'terms':
				return bsup_picker_control(field, value, onChange);
			case 'repeater':
				return bsup_repeater_control(field, value, onChange);
			default:
				return el(c.TextControl, Object.assign({}, common, {
					type: 'url' === field.type ? 'url' : 'text',
					placeholder: field.placeholder || '',
					value: value || '',
					onChange: onChange
				}));
		}
	}

	/**
	 * Register one block.
	 *
	 * @param {Object} def Definition from PHP.
	 */
	function bsup_register(def) {
		registerBlock(def.name, {
			apiVersion: 3,
			title: def.title,
			description: def.description,
			icon: def.icon,
			category: 'support-desk',
			attributes: def.attributes,
			supports: { html: false, align: false },

			edit: function (props) {
				var attributes = props.attributes;
				var blockProps = be.useBlockProps({ className: 'bsup-block-editor' });

				var sidebar = el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: i18n.settings, initialOpen: true },
						def.fields.map(function (field) {
							if (!bsup_visible(field, attributes)) {
								return null;
							}
							return el(Fragment, { key: field.name }, bsup_field(field, attributes[field.name], function (val) {
								var next = {};
								next[field.name] = val;
								props.setAttributes(next);
							}));
						})
					)
				);

				if (def.inner) {
					// Content block: heading preview + normal blocks inside.
					return el(
						'div',
						blockProps,
						sidebar,
						attributes.section_tag ? el('span', { className: 'section-tag' }, attributes.section_tag) : null,
						attributes.heading ? el('h2', { className: 'content-block-module__heading' }, attributes.heading) : null,
						el(be.InnerBlocks, { templateLock: false, template: [['core/paragraph', { placeholder: i18n.contentTip }]] })
					);
				}

				return el(
					'div',
					blockProps,
					sidebar,
					el(c.Disabled, null, el(ServerSideRender, { block: def.name, attributes: attributes }))
				);
			},

			save: function () {
				return def.inner ? el(be.InnerBlocks.Content) : null;
			}
		});
	}

	(data.blocks || []).forEach(bsup_register);
})(window.wp, window.bsupBlocks);
