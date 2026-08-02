/**
 * @file
 * Search result cards for ComplianceIQ.
 *
 * Registers two Scolta renderers: a result renderer that puts what kind of
 * document a hit is, which framework it belongs to and how urgent it is under
 * the title as badges, and a suggestion renderer that puts the document type
 * on each search-as-you-type row. Both read from the search index — the labels
 * ride along in the fragment's meta map, put there by
 * complianceiq_scolta_scolta_content_item_alter() — so neither costs a
 * per-result server call.
 *
 * There is no thumbnail here, on purpose. This corpus is regulation text,
 * guidance, enforcement cases and checklists, and it carries no per-item image
 * at all. A stock gavel photograph on a GDPR article would be decoration
 * pretending to be information, and it would push the excerpt, which is the
 * part a compliance search actually reads, further down the card.
 *
 * Load order matters. scolta.js defines window.Scolta when it executes and
 * Drupal's scolta bridge behavior calls Scolta.init() on DOMContentLoaded, so
 * this file must run after the former and before the latter. Declaring
 * scolta/search as a dependency and leaving the library in the footer puts it
 * exactly there; registering at top level (not inside a DOMContentLoaded
 * handler) keeps it there.
 */
(function (global) {
  'use strict';

  if (!global.Scolta || typeof global.Scolta.setResultRenderer !== 'function') {
    // A bundle without the render seam is not something to work around here.
    console.warn('[complianceiq] Scolta.setResultRenderer unavailable; leaving the built-in card in place.');
    return;
  }

  var ENTITIES = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  };

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/[&<>"']/g, function (c) { return ENTITIES[c]; });
  }

  /**
   * How many badges a card paints. Mirrors the indexer's own cap, which is
   * what actually bounds the string; this is the client-side belt to it.
   */
  var BADGE_LIMIT = 3;

  /**
   * Badge kinds the stylesheet has a treatment for.
   *
   * A severity is a risk level and gets the theme's alert colours; everything
   * else is a category and gets the neutral chip. An unknown kind falls back
   * to the neutral chip rather than emitting a class nothing styles, so a kind
   * added to the indexer later degrades instead of rendering unstyled.
   */
  var KINDS = { type: 1, regulation: 1, severity: 1, jurisdiction: 1 };

  /**
   * Severity values the stylesheet colours.
   *
   * Lowercased and checked against a fixed set rather than interpolated, so a
   * severity value the site adds later cannot inject a class name.
   */
  var SEVERITIES = { critical: 1, high: 1, medium: 1, low: 1 };

  /**
   * Renders a result's badges.
   *
   * data.meta.badges is raw index data: a JSON-encoded array of [kind, label]
   * pairs, already ordered, deduplicated and capped by
   * complianceiq_scolta_scolta_content_item_alter(). Pairs rather than bare
   * strings so severity can be coloured without the renderer guessing from the
   * text — a future framework named "Critical" could not paint itself red.
   *
   * Anything that does not parse into an array of pairs counts as no badges.
   * An item without them simply shows none, rather than a broken card.
   */
  function badges(encoded) {
    if (!encoded) {
      return '';
    }
    var pairs;
    try {
      pairs = JSON.parse(encoded);
    } catch (e) {
      return '';
    }
    if (!Array.isArray(pairs)) {
      return '';
    }
    var out = '';
    for (var i = 0; i < pairs.length && i < BADGE_LIMIT; i++) {
      var pair = pairs[i];
      if (!Array.isArray(pair) || pair.length < 2) {
        continue;
      }
      var kind = String(pair[0] || '');
      var label = String(pair[1] === null || pair[1] === undefined ? '' : pair[1]).trim();
      if (label === '') {
        continue;
      }
      var cls = 'complianceiq-result__badge';
      if (KINDS[kind]) {
        cls += ' complianceiq-result__badge--' + kind;
      }
      if (kind === 'severity' && SEVERITIES[label.toLowerCase()]) {
        cls += ' complianceiq-result__badge--sev-' + label.toLowerCase();
      }
      out += '<span class="' + cls + '">' + escapeHtml(label) + '</span>';
    }
    return out;
  }

  /**
   * Renders one result.
   *
   * Escaping: every ctx value used here ends in Html, Attr or Text, or is
   * safeUrl, so Scolta has already escaped it exactly as its own card would.
   * Everything read from data.meta is raw index data and is escaped here.
   * ctx.query and ctx.highlightTerms are raw and never reach the markup.
   *
   * An item with no badges gets this same card with an empty meta row, never
   * Scolta's built-in one. Mixing two card designs down a single result list
   * reads as a broken page rather than a designed fallback.
   */
  global.Scolta.setResultRenderer(function (data, ctx) {
    var meta = (data && data.meta) || {};
    var badgeHtml = badges(meta.badges);

    var metaRow = '';
    if (ctx.dateHtml || badgeHtml) {
      metaRow = '<div class="complianceiq-result__meta">'
        + badgeHtml
        + (ctx.dateHtml ? '<span class="complianceiq-result__date">' + ctx.dateHtml + '</span>' : '')
        + '</div>';
    }

    // target/rel match the built-in card: within one result list, no card may
    // open differently from its neighbour.
    return '<div class="scolta-result-card complianceiq-result">'
      + '<a class="scolta-result-title complianceiq-result__title" href="' + ctx.safeUrl + '"'
      + ' target="_blank" rel="noopener" title="' + ctx.titleAttr + '">' + ctx.titleHtml + '</a>'
      + metaRow
      + '<div class="scolta-result-excerpt complianceiq-result__excerpt">' + ctx.excerptHtml + '</div>'
      + '</div>';
  });

  // Behind its own guard rather than the file-level one: this seam landed
  // after setResultRenderer, so a bundle old enough to lack it still gets the
  // cards above, and the dropdown degrades to the themed but untagged rows
  // instead of throwing.
  if (typeof global.Scolta.setSuggestionRenderer !== 'function') {
    return;
  }

  /**
   * Renders one search-as-you-type suggestion row.
   *
   * Returns the row's INNER markup only. The option element around it is the
   * bundle's, and it is what carries the combobox contract — role="option",
   * the stable id the input's aria-activedescendant points at, aria-selected,
   * the data-scolta-sayt-index the keyboard and click handlers dispatch on,
   * and the href in navigate mode. None of that is restated here, because a
   * renderer cannot break by omission what it never writes.
   *
   * Where a demo with pictures puts a thumbnail, this row puts the document
   * type. On this corpus that is the fact worth the width: a query like
   * "breach notification" matches the regulation text, the guidance on it, an
   * enforcement case about it and a checklist for it, and which one you want
   * depends entirely on why you are asking. Rows with no type still reserve
   * the column, so the titles stay aligned down the list.
   *
   * Escaping: ctx.titleHtml and ctx.excerptHtml arrive pre-escaped, escaped
   * exactly as the built-in row escapes them. suggestion.meta.* is raw index
   * data and is escaped here. ctx.query is raw and never reaches the markup.
   *
   * A recent search is handed back to the built-in row by returning null: it
   * has no fragment, no document type and nothing to add, and the built-in row
   * is already the themed glyph treatment this dropdown wants for history.
   */
  global.Scolta.setSuggestionRenderer(function (suggestion, ctx) {
    if (!suggestion || suggestion.type !== 'title') {
      return null;
    }

    var meta = suggestion.meta || {};
    var docType = String(meta.doc_type || '').trim();

    // Decorative and aria-hidden: an option's accessible name is computed from
    // its contents, so this would otherwise be announced in front of the title
    // it qualifies — "Enforcement Case, Meta Platforms fined 1.2 billion".
    // The title names the row.
    var tag = docType === ''
      ? '<span class="complianceiq-sayt__type complianceiq-sayt__type--empty" aria-hidden="true"></span>'
      : '<span class="complianceiq-sayt__type" aria-hidden="true">' + escapeHtml(docType) + '</span>';

    return '<span class="complianceiq-sayt">'
      + tag
      // Both classes on purpose. The scolta-* one carries the look the theme
      // already gives a suggestion's title and excerpt, so a title row and a
      // recent-search row stay typographically identical; the complianceiq-*
      // one adds only the layout this row needs. Two classes at the same
      // specificity, resolved by source order, rather than a nested selector.
      + '<span class="complianceiq-sayt__text">'
      + '<span class="scolta-sayt-title complianceiq-sayt__title">' + ctx.titleHtml + '</span>'
      + (ctx.excerptHtml
        ? '<span class="scolta-sayt-excerpt complianceiq-sayt__excerpt">' + ctx.excerptHtml + '</span>'
        : '')
      + '</span>'
      + '</span>';
  });

})(window);
