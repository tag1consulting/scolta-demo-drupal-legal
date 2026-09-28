/**
 * @file
 * Searches the site with Scolta before each chatbot turn is posted.
 *
 * Scolta search runs only in the browser (Pagefind plus the scolta-core
 * WebAssembly module), so retrieval for a chat turn happens here, before the
 * turn leaves: rewrite the turn into a standalone query, expand it, search,
 * re-rank, keep the best five, extract context within a character budget,
 * and add all of it to the DeepChat request body as `scolta`. The
 * scolta_grounded chat processor answers from exactly those results.
 *
 * The retrieval duplicates the follow up path of scolta.js
 * (searchForFollowUpContext) plus its expansion merge, simplified: scolta.js
 * exports no module API, and this proof of concept must not edit it. What is
 * left out of the merge: sub-word admission, agreement bonus and specificity
 * weighting.
 *
 * It also turns the Scolta follow up box into a hand off: a follow up typed
 * under a search summary opens the chatbot, seeded with that search.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const S = drupalSettings.scoltaChatPoc || {};
  const TOP_N = 5;
  const LOAD_N = 20;

  // One entry per turn, for the evaluation run: what was searched, how much
  // context went out and how long each step took.
  const stats = (window.scoltaChatPocStats = window.scoltaChatPocStats || []);

  // Copied from scolta.js so the query is tokenized exactly as search does.
  const STOPWORDS = new Set([
    // Articles
    'a', 'an', 'the',
    // Personal pronouns
    'i', 'me', 'my', 'myself', 'mine', 'we', 'us', 'our', 'ours', 'ourselves',
    'you', 'your', 'yours', 'yourself', 'yourselves',
    'he', 'him', 'his', 'himself', 'she', 'her', 'hers', 'herself',
    'it', 'its', 'itself', 'they', 'them', 'their', 'theirs', 'themselves',
    'one', 'ones',
    // Demonstrative & relative pronouns
    'this', 'that', 'these', 'those', 'who', 'whom', 'whose', 'which', 'what',
    // Prepositions
    'about', 'above', 'across', 'after', 'against', 'along', 'among', 'around',
    'at', 'before', 'behind', 'below', 'beneath', 'beside', 'besides', 'between',
    'beyond', 'by', 'despite', 'down', 'during', 'except', 'for', 'from',
    'in', 'inside', 'into', 'like', 'near', 'of', 'off', 'on', 'onto',
    'out', 'outside', 'over', 'past', 'per', 'since', 'through', 'throughout',
    'to', 'toward', 'towards', 'under', 'underneath', 'until', 'up', 'upon',
    'with', 'within', 'without',
    // Conjunctions
    'and', 'but', 'or', 'nor', 'so', 'yet', 'both', 'either', 'neither',
    'although', 'because', 'however', 'if', 'once', 'than',
    'though', 'unless', 'when', 'whenever', 'where', 'wherever', 'while', 'whether',
    // Auxiliary & modal verbs
    'am', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
    'have', 'has', 'had', 'having', 'do', 'does', 'did', 'doing', 'done',
    'will', 'would', 'shall', 'should', 'can', 'could', 'may', 'might', 'must', 'ought',
    // Contractions (punctuation-stripped)
    'dont', 'doesnt', 'didnt', 'isnt', 'arent', 'wasnt', 'werent',
    'wont', 'wouldnt', 'shouldnt', 'couldnt', 'cant', 'cannot',
    'hasnt', 'havent', 'hadnt', 'mustnt',
    'im', 'ive', 'ill', 'youre', 'youve', 'youd', 'youll',
    'hes', 'shes', 'weve', 'theyre', 'theyve', 'theyd', 'theyll',
    'whats', 'whos', 'thats', 'theres', 'heres', 'lets',
    // Adverbs & degree words
    'also', 'always', 'ever', 'here', 'there', 'how', 'just',
    'never', 'now', 'often', 'only', 'quite', 'really',
    'still', 'then', 'too', 'very', 'well', 'already',
    'almost', 'even', 'much', 'rather', 'again', 'perhaps',
    'anyway', 'anymore', 'elsewhere', 'everywhere', 'somehow', 'why',
    // Determiners & quantifiers
    'all', 'another', 'any', 'each', 'every', 'few', 'many',
    'more', 'most', 'no', 'none', 'not', 'other', 'others',
    'own', 'same', 'several', 'some', 'such', 'enough',
    // Query-intent verbs (meta-language, not what users seek)
    'find', 'finding', 'found', 'need', 'needs', 'needed', 'needing',
    'want', 'wants', 'wanted', 'wanting', 'look', 'looking', 'looked', 'looks',
    'search', 'searching', 'searched', 'show', 'showing', 'shown', 'shows',
    'tell', 'telling', 'told', 'tells', 'give', 'giving', 'gave', 'given', 'gives',
    'help', 'helping', 'helped', 'helps', 'know', 'knowing', 'knew', 'known', 'knows',
    'see', 'seeing', 'saw', 'seen', 'sees', 'try', 'trying', 'tried', 'tries',
    'ask', 'asking', 'asked', 'asks', 'think', 'thinking', 'thought', 'thinks',
    'seem', 'seems', 'seemed', 'seeming', 'say', 'saying', 'said', 'says',
    // Common filler & function words
    'able', 'ago', 'away', 'back', 'else', 'far', 'got', 'gonna', 'gotta',
    'hence', 'hereby', 'herein', 'instead', 'merely', 'please', 'regarding',
    'therefore', 'thus', 'via', 'vs', 'whereas', 'whereby', 'wherein',
    'whatever', 'whichever', 'whoever', 'yes', 'ok', 'okay',
  ]);

  function extractSearchTerms(query) {
    const customStops = ((S.scoring && S.scoring.CUSTOM_STOP_WORDS) || []).map(w => String(w).toLowerCase());
    const stops = customStops.length ? new Set([...STOPWORDS, ...customStops]) : STOPWORDS;
    const words = query.toLowerCase().split(/\s+/).filter(w => w.length > 0);
    const meaningful = words
      .map(w => w.replace(/[^\w]/g, ''))
      .filter(w => !stops.has(w) && w.length > 1);
    return meaningful.length === 0 ? words.filter(w => w.length > 2) : meaningful;
  }

  function stripHtml(text) {
    const doc = new DOMParser().parseFromString(String(text ?? ''), 'text/html');
    return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function normalizeUrl(u) {
    return (u || '').replace(/\.html$/, '').replace(/\/$/, '').toLowerCase();
  }

  function absoluteUrl(u) {
    return u && u.startsWith('/') ? window.location.origin + u : (u || '');
  }

  // ---------------------------------------------------------------------------
  // Search engine: Pagefind plus the scolta-core WebAssembly module.
  // ---------------------------------------------------------------------------

  let enginePromise = null;
  let pagefindBase = '';

  function engine() {
    if (!enginePromise) {
      enginePromise = (async () => {
        const pagefind = await import(S.pagefindPath);
        await pagefind.init();
        pagefindBase = S.pagefindPath.replace(/\/pagefind\/pagefind\.js.*$/, '');
        // The corpus size the specificity and sub-word guards need, read
        // from the entry file as scolta.js does.
        let corpusTotal = 0;
        try {
          const entry = await (await fetch(S.pagefindPath.replace(/pagefind\.js(\?.*)?$/, '') + 'pagefind-entry.json')).json();
          corpusTotal = Object.values(entry.languages || {}).reduce((sum, l) => sum + (l.page_count || 0), 0);
        }
        catch (e) {
          corpusTotal = 0;
        }
        let wasm = null;
        try {
          wasm = await import(S.wasmPath);
          await wasm.default();
        }
        catch (e) {
          console.warn('[scolta-chat] WASM unavailable, ranking by Pagefind order:', e.message);
          wasm = null;
        }
        return { pagefind, wasm, corpusTotal };
      })();
      enginePromise.catch(() => { enginePromise = null; });
    }
    return enginePromise;
  }

  // pagefind's fullUrl() prepends its base path to root relative URLs.
  function resolveUrl(raw) {
    if (!raw) return '';
    if (/^https?:\/\//.test(raw)) return raw;
    if (pagefindBase && raw.startsWith(pagefindBase + '/')) return raw.slice(pagefindBase.length);
    return raw.startsWith('/') ? raw : '/' + raw;
  }

  function wasmConfig() {
    const out = {};
    for (const [k, v] of Object.entries(S.scoring || {})) out[k.toLowerCase()] = v;
    return out;
  }

  // scolta.js specificityWeight(): damp a term by how common it is, so a
  // ubiquitous word does not count the same as a rare on-intent one.
  function specificityWeight(df, total) {
    if (!total || !df) return null;
    const floor = (S.scoring && S.scoring.SPECIFICITY_FLOOR != null) ? S.scoring.SPECIFICITY_FLOOR : 0.15;
    const idf = Math.log(total / Math.min(df, total)) / Math.log(total + 1);
    return Math.max(floor, Math.min(idf, 1));
  }

  async function documentFrequency(eng, term) {
    return (await eng.pagefind.search(term)).results.length;
  }

  // Load the top results of one Pagefind search and score them the way
  // scolta.js scoreResults() does.
  async function loadScored(eng, query, weight, primaryQuery) {
    const search = await eng.pagefind.search(query);
    const toLoad = Math.min(search.results.length, LOAD_N);
    if (toLoad === 0) return [];
    const loaded = await Promise.all(search.results.slice(0, toLoad).map(r => r.data()));
    if (eng.wasm) {
      const input = {
        query: query,
        results: loaded.map((data, i) => ({
          title: data.meta?.title || '',
          url: resolveUrl(data.url || ''),
          excerpt: data.excerpt || '',
          date: data.meta?.date || '',
          pagefind_index: i,
          score: loaded.length > 1 ? 1 - (i / (loaded.length - 1)) : 1,
          locations: data.locations || [],
        })),
        config: wasmConfig(),
        primary_query: primaryQuery || undefined,
      };
      try {
        return JSON.parse(eng.wasm.score_results(JSON.stringify(input))).map(item => ({
          data: loaded[item.pagefind_index] || loaded[0],
          score: item.score * weight,
        }));
      }
      catch (e) {
        console.warn('[scolta-chat] score_results failed, using Pagefind order:', e.message);
      }
    }
    return loaded.map((data, i) => ({ data, score: (loaded.length > 1 ? 1 - i / (loaded.length - 1) : 1) * weight }));
  }

  // Merge the primary and expanded result sets, keeping the best score per
  // URL, as scolta.js mergeResults() does.
  function merge(eng, sets) {
    const byUrl = new Map();
    for (const set of sets) {
      for (const r of set) {
        const key = normalizeUrl(resolveUrl(r.data.url || ''));
        if (!byUrl.has(key) || r.score > byUrl.get(key).score) byUrl.set(key, r);
      }
    }
    if (eng.wasm) {
      try {
        const input = {
          sets: sets.map(set => ({
            results: set.map(r => ({
              title: r.data.meta?.title || '',
              url: resolveUrl(r.data.url || ''),
              score: r.score,
              excerpt: r.data.excerpt || '',
              date: r.data.meta?.date || '',
            })),
            weight: 1.0,
          })),
          deduplicate_by: 'url',
          normalize_urls: true,
        };
        const merged = JSON.parse(eng.wasm.merge_results(JSON.stringify(input)));
        return merged
          .map(item => {
            const found = byUrl.get(normalizeUrl(item.url || ''));
            return found ? { data: found.data, score: item.score } : null;
          })
          .filter(Boolean);
      }
      catch (e) {
        console.warn('[scolta-chat] merge_results failed, using max score merge:', e.message);
      }
    }
    return [...byUrl.values()];
  }

  // scolta.js deduplicateByTitle(): Jaccard over title words.
  function deduplicateByTitle(results) {
    const kept = [];
    const seen = [];
    for (const r of results) {
      const base = (r.data.meta?.title || '').toLowerCase().split('|')[0].trim();
      const words = new Set(base.replace(/[^\w\s]/g, '').split(/\s+/).filter(w => w.length > 2));
      if (words.size === 0) { kept.push(r); continue; }
      const dup = seen.some(other => {
        const inter = [...words].filter(w => other.has(w)).length;
        const union = new Set([...words, ...other]).size;
        const smaller = Math.min(words.size, other.size);
        return (union > 0 && inter / union >= 0.6) || (inter >= 3 && inter / smaller >= 0.6);
      });
      if (!dup) { seen.push(words); kept.push(r); }
    }
    return kept;
  }

  async function expand(query) {
    if (!S.scoring || !S.scoring.AI_EXPAND_QUERY) return null;
    try {
      const resp = await fetch(S.endpoints.expand, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query }),
      });
      if (!resp.ok) return null;
      const data = await resp.json();
      if (Array.isArray(data)) return { terms: data };
      return Array.isArray(data?.terms) ? { terms: data.terms } : null;
    }
    catch (e) {
      return null;
    }
  }

  // Build the context the way scolta.js summarizeResults() does, with the
  // budget split across the results: batch_extract_context's max_length is
  // a cap per item, not a total.
  function buildContext(eng, query, top) {
    const budget = Math.max(500, S.contextChars || 6000);
    const items = top.map(r => ({
      content: stripHtml(r.data.content || r.data.excerpt || ''),
      url: absoluteUrl(resolveUrl(r.data.url || '')),
      title: r.data.meta?.title || 'Untitled',
    }));
    // The "[n] title" and URL lines count against the budget too.
    const headers = items.reduce((sum, it, i) => sum + `[${i + 1}] ${it.title}\n${it.url}\n\n\n`.length, 0);
    const perItem = Math.max(100, Math.floor((budget - headers) / Math.max(1, items.length)));
    let extracted = null;
    if (eng.wasm) {
      try {
        extracted = JSON.parse(eng.wasm.batch_extract_context(JSON.stringify({
          query,
          items,
          config: { max_length: perItem, intro_length: 200, snippet_radius: 80, separator: '\n\n---\n\n' },
        })));
      }
      catch (e) {
        console.warn('[scolta-chat] batch_extract_context failed, cutting excerpts:', e.message);
      }
    }
    if (!extracted) {
      extracted = items.map(it => ({ title: it.title, url: it.url, context: it.content.substring(0, perItem) }));
    }
    return extracted.map((item, i) => `[${i + 1}] ${item.title}\n${item.url}\n${item.context}`).join('\n\n');
  }

  // The whole retrieval for one standalone query.
  async function retrieve(query) {
    const eng = await engine();
    const terms = extractSearchTerms(query);
    const searchQuery = terms.length ? terms.join(' ') : query;
    const expansionPromise = expand(query);

    let primary = await loadScored(eng, searchQuery, 1.0, searchQuery);
    // scolta.js OR fallback: only when the AND search found nothing.
    if (primary.length === 0 && terms.length > 1) {
      const orSets = await Promise.all(terms.map(async t => {
        const w = specificityWeight(await documentFrequency(eng, t), eng.corpusTotal);
        return loadScored(eng, t, 0.6 * (w ?? 1), searchQuery);
      }));
      // Scores add up across the OR terms, so a page matching most of the
      // question outranks one matching a single rare word. This stands in
      // for the agreement bonus scolta.js applies after its OR fallback.
      const summed = new Map();
      for (const set of orSets) {
        for (const r of set) {
          const key = normalizeUrl(resolveUrl(r.data.url || ''));
          const prev = summed.get(key);
          summed.set(key, prev ? { data: prev.data, score: prev.score + r.score } : { data: r.data, score: r.score });
        }
      }
      primary = [...summed.values()];
    }

    const expansion = await expansionPromise;
    const lowerQuery = query.toLowerCase();
    const expandedTerms = ((expansion && expansion.terms) || [])
      .filter(t => typeof t === 'string' && t && t.toLowerCase() !== lowerQuery && t.toLowerCase() !== searchQuery);
    // Each expansion phrase, then its words where the sub-word guard admits
    // them (scolta.js subwordAllowed(): rarer than EXPAND_SUBWORD_MAX_FREQ of
    // the corpus, or typed by the visitor), weights decaying as scolta.js
    // does and damped by specificity.
    const base = (S.scoring && S.scoring.EXPAND_PRIMARY_WEIGHT) || 0.5;
    const maxFreq = (S.scoring && S.scoring.EXPAND_SUBWORD_MAX_FREQ != null) ? S.scoring.EXPAND_SUBWORD_MAX_FREQ : 0.05;
    const typed = new Set(terms);
    const queries = [];
    const seen = new Set();
    for (const term of expandedTerms) {
      if (seen.has(term)) continue;
      seen.add(term);
      queries.push({ term, weight: Math.max(base - queries.length * 0.05, 0.1), phrase: true });
      const words = extractSearchTerms(term);
      if (words.length > 1) {
        for (const word of words) {
          if (seen.has(word) || word.length <= 2) continue;
          seen.add(word);
          queries.push({ term: word, weight: Math.max(base - queries.length * 0.05, 0.1), phrase: false });
        }
      }
    }
    const dfs = await Promise.all(queries.map(q => documentFrequency(eng, q.term)));
    const admitted = queries.filter((q, i) => {
      if (q.phrase) return dfs[i] > 0;
      const allowed = typed.has(q.term) || (eng.corpusTotal > 0 && dfs[i] / eng.corpusTotal < maxFreq);
      return allowed && dfs[i] > 0;
    });
    const expandedSets = await Promise.all(admitted.map(q => {
      const w = specificityWeight(dfs[queries.indexOf(q)], eng.corpusTotal);
      return loadScored(eng, q.term, q.weight * (w ?? 1), searchQuery);
    }));

    // Like scolta.js: the expansion sets pool first (best score per page),
    // then that pool merges against the primary set once. Merging every set
    // in one call hands a generic page that matches many expansion terms a
    // cross-list bonus per set, and it floats to the top of every query.
    let all = primary;
    if (expandedSets.length) {
      const pooled = new Map();
      for (const set of expandedSets) {
        for (const r of set) {
          const key = normalizeUrl(resolveUrl(r.data.url || ''));
          if (!pooled.has(key) || r.score > pooled.get(key).score) pooled.set(key, r);
        }
      }
      all = merge(eng, [primary, [...pooled.values()]]);
    }
    all.sort((a, b) => b.score - a.score);
    all = deduplicateByTitle(all);
    const top = all.slice(0, TOP_N);

    return {
      query,
      expanded_terms: expandedTerms,
      results: top.map((r, i) => ({
        n: i + 1,
        title: r.data.meta?.title || 'Untitled',
        url: absoluteUrl(resolveUrl(r.data.url || '')),
        excerpt: stripHtml(r.data.excerpt || ''),
      })),
      context: top.length ? buildContext(eng, searchQuery, top) : '',
    };
  }

  // ---------------------------------------------------------------------------
  // Query rewrite: resolve "what about for contractors?" against the thread.
  // ---------------------------------------------------------------------------

  async function csrfToken() {
    const resp = await fetch(S.endpoints.sessionToken, { credentials: 'same-origin' });
    return resp.ok ? resp.text() : '';
  }

  async function rewrite(message, history) {
    if (history.length === 0) {
      return { query: message, needs_search: true, rewritten: false };
    }
    try {
      const resp = await fetch(S.endpoints.rewrite, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': await csrfToken() },
        body: JSON.stringify({ message, history: history.slice(-4) }),
      });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const data = await resp.json();
      if (typeof data.query === 'string') return data;
    }
    catch (e) {
      console.warn('[scolta-chat] rewrite failed, searching the message as typed:', e.message);
    }
    return { query: message, needs_search: true, rewritten: false };
  }

  // ---------------------------------------------------------------------------
  // The thread as the browser sees it, for the rewrite's two exchanges.
  // The server keeps its own copy and never trusts this one.
  // ---------------------------------------------------------------------------

  let exchanges = [];
  let threadId = null;
  let pendingSeed = null;
  let pendingTurn = null;
  const sentUrls = new Set();

  function answerText(html) {
    const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
    doc.querySelectorAll('.scolta-chat-results').forEach(el => el.remove());
    return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function loadHistory() {
    const messages = (drupalSettings.ai_deepchat && drupalSettings.ai_deepchat.messages) || [];
    exchanges = messages.map(m => ({
      role: m.role === 'user' ? 'user' : 'assistant',
      content: answerText(m.html || m.text || ''),
    }));
  }

  async function buildTurn(text, t0) {
    const stat = { message: text, started: new Date().toISOString() };
    const r0 = performance.now();
    const rw = await rewrite(text, exchanges);
    stat.rewrite_ms = Math.round(performance.now() - r0);
    stat.rewritten_query = rw.query;
    stat.needs_search = rw.needs_search !== false;

    if (rw.needs_search === false) {
      return { stat, scolta: { query: rw.query || text, needs_search: false, results: [], context: '' } };
    }
    const s0 = performance.now();
    let scolta;
    try {
      scolta = await retrieve(rw.query || text);
      scolta.needs_search = true;
    }
    catch (e) {
      console.warn('[scolta-chat] search failed:', e);
      scolta = { query: rw.query || text, needs_search: true, search_failed: true, results: [], context: '' };
    }
    stat.search_ms = Math.round(performance.now() - s0);
    stat.results = scolta.results.map(r => r.url);
    stat.repeated_excerpts = scolta.results.filter(r => sentUrls.has(r.url)).length;
    stat.context_chars = scolta.context.length;
    stat.retrieval_ms = Math.round(performance.now() - t0);
    // An excerpt sent earlier goes again only because it is in this turn's
    // top five; nothing outside the top five is ever sent.
    scolta.results.forEach(r => sentUrls.add(r.url));
    return { stat, scolta };
  }

  function wrap(el) {
    if (el.scoltaChatWrapped) return;
    el.scoltaChatWrapped = true;
    loadHistory();

    const originalRequest = el.requestInterceptor;
    el.requestInterceptor = async (request) => {
      const t0 = performance.now();
      const req = (originalRequest ? await originalRequest.call(el, request) : null) || request;
      const body = req.body || {};
      if (body.thread_id && threadId && body.thread_id !== threadId) {
        // The visitor cleared the chat: a new thread starts from nothing.
        exchanges = [];
        sentUrls.clear();
      }
      threadId = body.thread_id || threadId;

      const messages = Array.isArray(body.messages) ? body.messages : [];
      const last = [...messages].reverse().find(m => m.role === 'user');
      const text = last ? String(last.text || '').trim() : '';
      if (!text) return req;

      if (pendingSeed) {
        exchanges = [
          { role: 'user', content: pendingSeed.query },
          { role: 'assistant', content: pendingSeed.summary },
        ];
      }
      const { stat, scolta } = await buildTurn(text, t0);
      if (pendingSeed) {
        scolta.seed = pendingSeed;
        stat.seeded = true;
        pendingSeed = null;
      }
      body.scolta = scolta;
      req.body = body;
      pendingTurn = { text, t0, stat };
      return req;
    };

    const originalResponse = el.responseInterceptor;
    el.responseInterceptor = (response) => {
      const out = originalResponse ? originalResponse.call(el, response) : response;
      if (pendingTurn && out && typeof out.html === 'string') {
        const answer = answerText(out.html);
        exchanges.push({ role: 'user', content: pendingTurn.text }, { role: 'assistant', content: answer });
        pendingTurn.stat.total_ms = Math.round(performance.now() - pendingTurn.t0);
        pendingTurn.stat.answer = answer;
        stats.push(pendingTurn.stat);
        pendingTurn = null;
      }
      return out;
    };

    // deep-chat scrolls to the bottom of every new message, which puts a long
    // answer's end in view. For a grounded answer, move the view back to the
    // top of the reply so it reads from its first line. onMessage fires once
    // the message is rendered; deepchat-init.js sets its own, so wrap it.
    const originalOnMessage = el.onMessage;
    el.onMessage = (event) => {
      if (originalOnMessage) originalOnMessage.call(el, event);
      const message = event && event.message;
      if (!message || message.role !== 'ai' || event.isHistory) return;
      if (typeof message.html !== 'string' || !message.html.includes('scolta-chat-results')) return;
      // After deep-chat's own scroll, including its 60 ms image-load retry.
      setTimeout(() => scrollToReplyTop(el), 80);
    };
  }

  function scrollToReplyTop(el) {
    const root = el.shadowRoot;
    const list = root && root.getElementById('messages');
    if (!list) return;
    const replies = root.querySelectorAll('.scolta-chat-results');
    const last = replies[replies.length - 1];
    const bubble = last && last.closest('.outer-message-container');
    if (!bubble) return;
    const top = bubble.getBoundingClientRect().top - list.getBoundingClientRect().top + list.scrollTop;
    list.scrollTop = Math.max(0, top - 8);
  }

  // ---------------------------------------------------------------------------
  // Hand off: a follow up under a search summary continues in the chat.
  // ---------------------------------------------------------------------------

  let lastSearch = null;

  document.addEventListener('scolta:results-rendered', (event) => {
    const detail = event.detail || {};
    lastSearch = {
      query: String(detail.query || ''),
      results: (detail.results || []).slice(0, TOP_N).map(r => ({
        title: (r.data && r.data.meta && r.data.meta.title) || '',
        url: absoluteUrl(resolveUrl((r.data && r.data.url) || '')),
      })).filter(r => r.url),
    };
  });

  // The summary's links become markdown so the seeded assistant turn keeps
  // its citations.
  function summaryMarkdown() {
    const el = document.getElementById('scolta-ai-summary-text');
    if (!el) return '';
    const clone = el.cloneNode(true);
    clone.querySelectorAll('a[href]').forEach(a => {
      a.replaceWith(`[${a.textContent}](${a.getAttribute('href')})`);
    });
    clone.querySelectorAll('li').forEach(li => { li.prepend('- '); li.append('\n'); });
    clone.querySelectorAll('p, h3, h4, h5').forEach(p => p.append('\n\n'));
    return (clone.textContent || '').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
  }

  function waitFor(test, timeoutMs) {
    return new Promise((resolve) => {
      const start = Date.now();
      (function poll() {
        if (test()) return resolve(true);
        if (Date.now() - start > timeoutMs) return resolve(false);
        setTimeout(poll, 50);
      })();
    });
  }

  // The widget has no API to open it; ai_chatbot's sticky script opens it
  // on a click of its header, so that is what this does.
  function openChat(chat) {
    const container = chat.closest('.chat-container');
    if (container && !container.classList.contains('chat-open')) {
      const header = container.querySelector('.ai-deepchat--header');
      if (header) header.click();
    }
  }

  async function handOff(event) {
    const field = document.getElementById('scolta-followup-field');
    const chat = document.querySelector('deep-chat.deepchat-element');
    const question = field ? field.value.trim() : '';
    if (!question || !chat || typeof chat.submitUserMessage !== 'function') {
      return; // Let Scolta's own follow up run.
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    field.value = '';

    const queryInput = document.getElementById('scolta-query');
    const seed = {
      query: (lastSearch && lastSearch.query) || (queryInput ? queryInput.value.trim() : ''),
      summary: summaryMarkdown(),
      results: (lastSearch && lastSearch.results) || [],
    };

    openChat(chat);

    // A hand off starts a fresh conversation, so the seed is its opening.
    if (exchanges.length > 0 && typeof Drupal.clearDeepchatMessages === 'function') {
      const before = drupalSettings.ai_deepchat.thread_id;
      Drupal.clearDeepchatMessages({ stopPropagation() {} });
      await waitFor(() => drupalSettings.ai_deepchat.thread_id !== before, 5000);
      await new Promise(r => setTimeout(r, 300));
    }
    exchanges = [];
    sentUrls.clear();
    pendingSeed = seed.query && seed.summary ? seed : null;
    // Give an opening widget a frame to lay out before the message lands.
    await new Promise(r => setTimeout(r, 150));
    chat.submitUserMessage({ text: question });
  }

  if (S.followupsOpenChat) {
    document.addEventListener('click', (event) => {
      if (event.target.closest && event.target.closest('[data-scolta-followup-submit]')) {
        handOff(event);
      }
    }, true);
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && event.target.closest && event.target.closest('[data-scolta-followup-input]')) {
        handOff(event);
      }
    }, true);
  }

  // ---------------------------------------------------------------------------
  // Attach: wrap what deepchat-init.js set up rather than replace it.
  // ---------------------------------------------------------------------------

  // The retrieval alone, for evaluating it without the model.
  Drupal.scoltaChatPoc = { retrieve };

  Drupal.behaviors.scoltaChatPoc = {
    attach(context) {
      once('scolta-chat-poc', 'deep-chat.deepchat-element', context).forEach((chat) => {
        // deepchat-init.js sets its interceptors in its own attach, which
        // runs first (this library depends on it), so they are in place.
        wrap(chat);
        // Warm the index so the first turn does not pay for it.
        engine().catch(() => {});
      });
    },
  };
})(Drupal, drupalSettings, once);
