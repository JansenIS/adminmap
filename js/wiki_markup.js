(function () {
  "use strict";

  function escapeHtml(v) {
    return String(v ?? "").replace(/[&<>"']/g, (s) => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s]));
  }

  function inline(text) {
    let out = escapeHtml(text);
    out = out.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
    out = out.replace(/\*(.+?)\*/g, "<em>$1</em>");
    out = out.replace(/`([^`]+)`/g, "<code>$1</code>");
    return out;
  }

  function toHtml(source) {
    const raw = String(source ?? "").replace(/\r\n?/g, "\n").trim();
    if (!raw) return "";

    const blocks = raw.split(/\n{2,}/);
    const html = [];

    for (const block of blocks) {
      const lines = block.split("\n");
      if (lines.every((l) => /^\s*[-*]\s+/.test(l))) {
        html.push("<ul>" + lines.map((l) => `<li>${inline(l.replace(/^\s*[-*]\s+/, ""))}</li>`).join("") + "</ul>");
        continue;
      }

      const h = lines[0].match(/^\s{0,3}(#{1,3})\s+(.+)$/);
      if (h && lines.length === 1) {
        html.push(`<h${h[1].length}>${inline(h[2])}</h${h[1].length}>`);
        continue;
      }

      html.push(`<p>${lines.map(inline).join("<br>")}</p>`);
    }

    return html.join("\n");
  }

  window.AdminMapWikiMarkup = { toHtml };
})();
