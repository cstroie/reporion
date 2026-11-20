# websearch: Ask the archive on the web (D67)

Enable it in Admin → Plugins. Ask the archive then also searches the web,
with the short English query the `ask-expand` prompt writes on its last line
(`web: …`; the shipped radiology prompt asks for it, an older prompt page
without the line means no web search). The query is sent only when it holds
no report's name word, no identifier and no four-digit number; the question
itself is never sent.

Settings: `provider` (`ddg`: DuckDuckGo's HTML page, no key, may be rate
limited; `brave`: the Brave Search API, `brave_key`), `max_results`,
`fetch_pages` (the top results read for their best passage; 0 = snippets
only), `timeout`.

Pages are read over https only, from hosts whose every address is public,
redirects checked one by one, at most 1 MB each. Port of
[cstroie/tusk](https://github.com/cstroie/tusk)'s `WebSearchTool`.
