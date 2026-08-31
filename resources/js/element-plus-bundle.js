// Element Plus samt eigenem CSS in einem eigenen Modul.
//
// Warum eine separate Datei: würden die CSS-Importe in app.js stehen, zöge
// Vite sie in pth.css – und die hängt als <link> im <head> auf jeder Seite,
// also render-blockierend, mit 377 KB. Seit die Komponenten lazy sind, wird
// Element Plus aber nur noch geladen, wenn wirklich eine montiert wird.
//
// Als dynamischer Import landen JS und CSS zusammen in einem Chunk. Vite
// schiebt das zugehörige <link> beim Laden des Chunks nach und wartet, bis es
// da ist, bevor das Modul auflöst – die Komponente mountet also nie vor ihrem
// Stylesheet.
// Statt theme-chalk/index.css (347 KB, laut CSS-Coverage über 15 Seiten in
// zwei Viewports zu 93 % ungenutzt) nur die Stylesheets der tatsächlich
// verwendeten Komponenten. Bewusst auf Datei- und nicht auf Regelebene: so
// bleiben alle Zustände einer Komponente erhalten – hover, focus, disabled,
// geöffnete Dropdowns –, die eine regelbasierte Analyse beim Messen nie
// vollständig zu sehen bekommt.
//
// Die Liste stammt aus den <el-*>-Tags in resources/js/components und
// resources/spectool. Kommt eine Komponente dazu, gehört sie hier ergänzt,
// sonst erscheint sie unformatiert.
import ElementPlus from 'element-plus'

// Variablen, Reset, Grundlagen.
import 'element-plus/theme-chalk/base.css'

// Direkt im Markup verwendet.
import 'element-plus/theme-chalk/el-autocomplete.css'
import 'element-plus/theme-chalk/el-button.css'
import 'element-plus/theme-chalk/el-card.css'
import 'element-plus/theme-chalk/el-checkbox.css'
import 'element-plus/theme-chalk/el-col.css'
import 'element-plus/theme-chalk/el-collapse.css'
// EP 2.9 hat mehrere Komponenten in Parent + *-item.css aufgeteilt. Die
// Parent-Datei enthält dann nur noch das Gerüst, das eigentliche Styling
// (Flexbox, Rotationen, Label-Layout, Validierungstexte) steckt im *-item.
import 'element-plus/theme-chalk/el-collapse-item.css'
import 'element-plus/theme-chalk/el-date-picker.css'
import 'element-plus/theme-chalk/el-descriptions.css'
import 'element-plus/theme-chalk/el-descriptions-item.css'
import 'element-plus/theme-chalk/el-dialog.css'
import 'element-plus/theme-chalk/el-dropdown.css'
import 'element-plus/theme-chalk/el-form.css'
import 'element-plus/theme-chalk/el-form-item.css'
import 'element-plus/theme-chalk/el-image.css'
import 'element-plus/theme-chalk/el-input.css'
import 'element-plus/theme-chalk/el-input-number.css'
import 'element-plus/theme-chalk/el-menu.css'
import 'element-plus/theme-chalk/el-pagination.css'
import 'element-plus/theme-chalk/el-progress.css'
import 'element-plus/theme-chalk/el-row.css'
import 'element-plus/theme-chalk/el-select.css'
import 'element-plus/theme-chalk/el-switch.css'
import 'element-plus/theme-chalk/el-table.css'
import 'element-plus/theme-chalk/el-tag.css'
import 'element-plus/theme-chalk/el-tooltip.css'

// Nicht im Markup, aber intern von den obigen gerendert: Select und
// Date-Picker hängen ihre Auswahl in einen Popper mit Scrollbar, die Tabelle
// zeigt bei leerem Ergebnis el-empty und beim Laden el-loading, der Dialog
// legt ein Overlay darüber, Icons stecken in fast jeder Komponente.
import 'element-plus/theme-chalk/el-empty.css'
import 'element-plus/theme-chalk/el-icon.css'
import 'element-plus/theme-chalk/el-image-viewer.css'
import 'element-plus/theme-chalk/el-loading.css'
import 'element-plus/theme-chalk/el-message.css'
import 'element-plus/theme-chalk/el-message-box.css'
import 'element-plus/theme-chalk/el-option.css'
import 'element-plus/theme-chalk/el-option-group.css'
import 'element-plus/theme-chalk/el-overlay.css'
import 'element-plus/theme-chalk/el-popover.css'
import 'element-plus/theme-chalk/el-popper.css'
import 'element-plus/theme-chalk/el-scrollbar.css'
import 'element-plus/theme-chalk/el-select-dropdown.css'
import 'element-plus/theme-chalk/el-text.css'
import 'element-plus/theme-chalk/el-time-picker.css'
import 'element-plus/theme-chalk/el-time-select.css'
import 'element-plus/theme-chalk/el-virtual-list.css'

// Dark-Mode-Variablen: app.js spiegelt phpBBs fd_dark auf html.dark.
import 'element-plus/theme-chalk/dark/css-vars.css'

export default ElementPlus
