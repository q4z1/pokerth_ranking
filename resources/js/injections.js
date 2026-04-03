import axios from 'axios'

console.log('[injections.js] loaded')

// ── 1. Profilzeilen: Länderflagge nach Username einfügen ──────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.has-profile').forEach(item => {
    const usernameEl = item.querySelector('a[class^=username]')
    if (!usernameEl) return
    axios.get(window.location.origin + '/pthranking/player/gender-country?u=' + usernameEl.textContent.trim())
      .then(res => {
        if (!res.data.country_iso) return
        const country = (window.countries || []).find(c => c.png === res.data.country_iso)
        const img = document.createElement('img')
        img.src    = '/images/flags/' + res.data.country_iso + '.svg'
        img.alt    = country?.title ?? res.data.country_iso
        img.title  = img.alt
        img.style.cssText = 'width:24px;height:14px;margin-top:2px'
        usernameEl.parentNode.append(document.createElement('br'), img)
      })
      .catch(err => console.log(err))
  })

  // ── 2. UCP-Formular: Land & Geschlecht ────────────────────────────────────
  const ucpForm = document.querySelector('form#ucp')
  if (!ucpForm || !document.getElementById('pf_phpbb_location')) return

  // countryflags-Layout-CSS nachladen (dd.css/gorilla-dropdown entfällt)
  const cssLink = document.createElement('link')
  cssLink.rel  = 'stylesheet'
  cssLink.href = '/pthranking/css/countryflags.css'
  document.head.appendChild(cssLink)

  const fieldset = ucpForm.querySelector('fieldset')

  // Land-Dropdown (natives <select>, kein jQuery-Plugin mehr)
  const countryDiv = document.createElement('div')
  countryDiv.className = 'pth_country'
  const selectEl = document.createElement('select')
  selectEl.name = 'pth_country'
  selectEl.id   = 'pth_country'
  const noneOpt = document.createElement('option')
  noneOpt.value       = ''
  noneOpt.textContent = 'none'
  selectEl.appendChild(noneOpt);
  (window.countries || []).forEach(c => {
    const opt = document.createElement('option')
    opt.value              = c.png
    opt.textContent        = c.title
    opt.dataset.imgsrc     = '/images/flags/' + c.svg + '.svg'
    selectEl.appendChild(opt)
  })
  countryDiv.innerHTML = '<div class="pth_label"><label for="pth_country">Country Flag:</label></div>'
  const selectWrap = document.createElement('div')
  selectWrap.className = 'pth_select'
  selectWrap.appendChild(selectEl)
  countryDiv.appendChild(selectWrap)

  // Geschlecht-Radiobuttons
  const genderDiv = document.createElement('div')
  genderDiv.className = 'pth_gender'
  genderDiv.innerHTML = `
    <div class="pth_label"><label>Gender:</label></div>
    <div>
      <input name="pth_gender" type="radio" class="inputbox autowidth" value="m"> <span>male</span>
      <input name="pth_gender" type="radio" class="inputbox autowidth" value="f"> <span>female</span>
      <input name="pth_gender" type="radio" class="inputbox autowidth" value=""> <span>none</span>
    </div>`

  fieldset.append(countryDiv, genderDiv)

  // Aktuelle Werte vom Server laden
  const loggedInEl = document.querySelector('#username_logged_in span[class^=username]')
  if (loggedInEl) {
    axios.get(window.location.origin + '/pthranking/player/gender-country?u=' + loggedInEl.textContent.trim())
      .then(res => {
        if (res.data.country_iso) {
          selectEl.value = res.data.country_iso
        }
        const radioVal = res.data.gender ?? ''
        const radio = genderDiv.querySelector(`input[value="${radioVal}"]`)
        if (radio) radio.checked = true
      })
      .catch(err => console.log(err))
  }

  // Formular abschicken
  let formDone = false
  ucpForm.addEventListener('submit', async event => {
    if (formDone) return
    event.preventDefault()

    const data = {}
    new FormData(ucpForm).forEach((val, key) => { data[key] = val })
    // Gewähltes Gender explizit setzen (ggf. nicht übertragen wenn keines gecheckt)
    const checkedGender = genderDiv.querySelector('input[name=pth_gender]:checked')
    data['pth_gender'] = checkedGender ? checkedGender.value : ''
    data['username']   = loggedInEl?.textContent.trim() ?? ''

    try {
      await axios.post(window.location.origin + '/pthranking/player/gender-country', data)
      formDone = true
      ucpForm.querySelector('input[name=submit]')?.click()
    } catch (err) {
      console.log(err)
    }
  })
})
