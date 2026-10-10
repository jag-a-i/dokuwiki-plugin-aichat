"""Real-browser E2E for the aichat UI against a synthetic DokuWiki + mock OpenAI-compatible endpoint.
Usage: python3 test_ui_e2e.py [base_url] [screenshot_dir]. Exit code 0 = all checks passed."""
import json, sys, urllib.parse
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8088'
SHOTS = sys.argv[2] if len(sys.argv) > 2 else '/tmp'
FOOTER = 'Please see the following articles for more information:'
NOINFO = 'The Wiki does not contain information on that topic.'
results, failed = [], []

def check(name, cond, detail=''):
    (results if cond else failed).append(name)
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else f'  -- {detail}'))

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page()
    errors, posts = [], []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: m.type == 'error' and errors.append(m.text))
    page.on('request', lambda r: 'call=aichat' in r.url and r.method == 'POST' and posts.append(r))

    # log in as the synthetic user
    page.goto(f'{BASE}/doku.php?id=start&do=login')
    page.fill('input[name=u]', 'alice')
    page.fill('input[name=p]', 'synthetic-alice-pw')
    page.click('#dw__login button[type=submit]')
    page.goto(f'{BASE}/doku.php?id=start')
    page.evaluate('sessionStorage.clear()')
    page.reload()
    chat = page.locator('aichat-chat')
    out = chat.locator('.output')

    def ask(text):
        n = len(posts)
        chat.locator('textarea').fill(text)
        chat.locator('textarea').press('Enter')
        page.wait_for_function(f'() => document.querySelector("aichat-chat").shadowRoot.querySelectorAll(".ai").length > 0', timeout=5000)
        page.wait_for_timeout(200)
        page.wait_for_function('() => document.querySelector("aichat-chat").shadowRoot.querySelector("progress").style.display === "none"', timeout=10000)
        return posts[n] if len(posts) > n else None

    def form(req):
        # multipart body -> dict of simple fields
        body = req.post_data or ''
        fields = {}
        for part in body.split('------')[1:]:
            if 'name="' in part:
                name = part.split('name="')[1].split('"')[0]
                fields[name] = part.split('\r\n\r\n', 1)[1].rsplit('\r\n', 1)[0] if '\r\n\r\n' in part else ''
        return fields

    # 1. normal submit -> real fetch -> clarification
    req = ask('How do I change my password?')
    check('normal submit performs a fetch', req is not None, 'no POST observed')
    f = form(req) if req else {}
    jsinfo_tok = page.evaluate('JSINFO.plugin_aichat && JSINFO.plugin_aichat.sectok')
    check('CSRF token picked up from JSINFO and sent', bool(f.get('sectok')) and f.get('sectok') == jsinfo_tok, f)
    check('first turn sends empty conversation/pending', f.get('conversation') == '' and f.get('pending') == '', f)
    last = out.locator('.ai').last
    buttons = last.locator('.options button')
    labels = [b.inner_text() for b in buttons.all()]
    names = sorted(l.split('. ', 1)[1] for l in labels)
    check('clarification shows exactly the grounded, numbered options', names == ['CRM', 'E-Mail', 'VPN']
          and [l.split('. ')[0] for l in labels] == ['1', '2', '3'], labels)
    check('clarification has no footer and no source list',
          FOOTER not in last.inner_text() and last.locator('ul').count() == 0, last.inner_text())
    check('ACL-denied HR option never shown', 'HR' not in out.inner_text())
    page.screenshot(path=f'{SHOTS}/e2e-1-clarify.png')

    # 2. clicked choice -> scoped answer
    n = len(posts)
    vpn = [i for i, l in enumerate(labels) if l.endswith('. VPN')]
    buttons.nth(vpn[0] if vpn else 0).click()
    page.wait_for_function('() => document.querySelector("aichat-chat").shadowRoot.querySelectorAll(".ai").length >= 3', timeout=10000)
    page.wait_for_function('() => document.querySelector("aichat-chat").shadowRoot.querySelector("progress").style.display === "none"', timeout=10000)
    f2 = form(posts[n]) if len(posts) > n else {}
    check('choice click sends label, server conversation id and pending id',
          f2.get('question') == 'VPN' and f2.get('conversation', '').startswith('c') and len(f2.get('pending', '')) == 24, f2)
    ans = out.locator('.ai').last
    text = ans.inner_text()
    check('scoped answer is about VPN only', 'VPN password' in text and 'E-Mail' not in text and 'CRM' not in text, text)
    check('footer appears exactly once', text.count(FOOTER) == 1, text)
    srcs = [a.inner_text() for a in ans.locator('ul a').all()]
    check('source list is the selected authorized page only', srcs == ['VPN password'], srcs)
    check('fabricated source link removed', 'Secret' not in text and 'it:hr' not in ans.inner_html(), ans.inner_html())
    check('options removed after choosing', out.locator('.options').count() == 0)

    # feedback on the answer (keyboard accessible, saved state, change of vote, category)
    fb = ans.locator('.feedback')
    check('answer has a labelled feedback group', fb.count() == 1 and fb.get_attribute('aria-label') == 'Was this answer helpful?')
    check('clarification had no feedback control', out.locator('.ai').nth(1).locator('.feedback').count() == 0)
    helpful = fb.locator('button.vote.helpful')
    helpful.focus()
    page.keyboard.press('Enter')
    page.wait_for_function('() => document.querySelector("aichat-chat").shadowRoot.querySelector(".feedback .status").textContent.length > 0', timeout=5000)
    check('keyboard vote saved with status message', 'saved' in fb.locator('.status').inner_text()
          and helpful.get_attribute('aria-pressed') == 'true', fb.inner_text())
    check('categories hidden for helpful', fb.locator('.categories').is_hidden())
    fb.locator('button.vote.not_helpful').click()
    page.wait_for_function('() => document.querySelector("aichat-chat").shadowRoot.querySelector("button.vote.not_helpful").getAttribute("aria-pressed") === "true"', timeout=5000)
    check('vote can be changed', helpful.get_attribute('aria-pressed') == 'false')
    check('categories shown for not helpful', fb.locator('.categories').is_visible())
    fb.locator('button.category.wrong_source').click()
    page.wait_for_function('() => document.querySelector("aichat-chat").shadowRoot.querySelector("button.category.wrong_source").getAttribute("aria-pressed") === "true"', timeout=5000)
    check('category saved', 'saved' in fb.locator('.status').inner_text())
    page.evaluate('JSINFO.plugin_aichat.sectok = "forged"')
    errors_before = len(errors)
    fb.locator('button.vote.helpful').click()
    page.wait_for_function('() => document.querySelector("aichat-chat").shadowRoot.querySelector(".feedback").classList.contains("error")', timeout=5000)
    check('failed save shows error state and keeps previous vote',
          'could not be saved' in fb.locator('.status').inner_text()
          and fb.locator('button.vote.not_helpful').get_attribute('aria-pressed') == 'true')
    del errors[errors_before:]  # the deliberate 403 of this step
    page.reload()
    page.wait_for_timeout(300)
    fb2 = out.locator('.ai').last.locator('.feedback')
    check('replay keeps the saved vote and category',
          fb2.locator('button.vote.not_helpful').get_attribute('aria-pressed') == 'true'
          and fb2.locator('button.category.wrong_source').get_attribute('aria-pressed') == 'true')
    page.screenshot(path=f'{SHOTS}/e2e-3-feedback.png')
    page.screenshot(path=f'{SHOTS}/e2e-2-answer.png')

    # 3. replay after reload: no duplicate footer, no stale options
    page.goto(f'{BASE}/doku.php?id=start')  # fresh page: fresh JSINFO token
    page.wait_for_timeout(300)
    replay = out.inner_text()
    check('replay keeps footer exactly once', replay.count(FOOTER) == 1, replay)
    check('replay shows no stale clarification buttons', out.locator('.options').count() == 0)

    # 4. legacy history row (pre-upgrade format, no meta) replays with sources, no footer added
    page.evaluate('''() => { sessionStorage.setItem("ai-chat-history", JSON.stringify([
        ["old question", "<p>Old answer text</p>", [{"page":"kitchen:coffee","url":"/doku.php?id=kitchen:coffee","title":"Coffee machine","score":"90.00%"}]]]));
        sessionStorage.removeItem("ai-chat-conversation"); sessionStorage.removeItem("ai-chat-pending"); }''')
    page.reload()
    page.wait_for_timeout(300)
    legacy = out.locator('.ai').last
    check('legacy row replays with its sources', [a.inner_text() for a in legacy.locator('ul a').all()] == ['Coffee machine'])
    check('legacy row gets no footer', FOOTER not in out.inner_text(), out.inner_text())

    # 5. page context toggle (regression for removed getPageContext)
    chat.locator('button.pagecontext').click()
    req = ask('How do I change my password?')
    fp = form(req) if req else {}
    check('page context sends JSINFO.id', fp.get('pagecontext') == 'start', fp)
    check('page context without matching content gives exact no-information text',
          out.locator('.ai').last.inner_text().strip() == NOINFO, out.locator('.ai').last.inner_text())
    chat.locator('button.pagecontext').click()

    # 6. reset clears state
    chat.locator('button.delete-history').click()
    page.wait_for_timeout(200)
    keys = page.evaluate('() => ["ai-chat-history","ai-chat-conversation","ai-chat-pending"].map(k => sessionStorage.getItem(k))')
    check('reset clears history, conversation and pending', all(k in (None, '', '[]') for k in keys), keys)
    check('reset leaves only the greeting', out.locator('.ai').count() == 1 and out.locator('.user').count() == 0)

    check('no JavaScript errors before the CSRF step', not errors, errors)
    errors.clear()

    # 7. bad CSRF token -> safe error with reference, no answer
    page.evaluate('JSINFO.plugin_aichat.sectok = "forged"')
    ask('How do I change my VPN password?')
    err = out.locator('.ai').last.inner_text()
    check('forged CSRF token gives safe error with reference', 'Reference:' in err and 'VPN password' not in err, err)

    check('CSRF step logs only the expected 403 response', errors == ['Failed to load resource: the server responded with a status of 403 (Forbidden)'], errors)
    browser.close()

print(f'\nE2E SUMMARY: {len(results)} passed, {len(failed)} failed')
sys.exit(1 if failed else 0)
