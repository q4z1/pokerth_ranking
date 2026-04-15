import axios from 'axios'

console.log('[injections.js] loaded')

function initInjections() {
  console.log('initInjections() called')
  // ── 1. Profilzeilen: Länderflagge nach Username einfügen ──────────────────────
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
  console.log('ucpForm', ucpForm)
  if (!ucpForm || !document.getElementById('pf_phpbb_location')) {
    console.log('conditions not met')
    return
  }

  // countryflags-Layout-CSS nachladen (dd.css/gorilla-dropdown entfällt)
  const cssLink = document.createElement('link')
  cssLink.rel  = 'stylesheet'
  cssLink.href = '/pthranking/css/countryflags.css'
  document.head.appendChild(cssLink)

  const fieldset = ucpForm.querySelector('fieldset')
  console.log('fieldset', fieldset)

  // Theme-aware Styles for the custom dropdown
  const computedBodyBg = window.getComputedStyle(document.body).backgroundColor
  const darkMode = (() => {
    const match = computedBodyBg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
    if (!match) return false
    const [r, g, b] = match.slice(1).map(Number)
    return (r * 0.299 + g * 0.587 + b * 0.114) < 128
  })()
  const selectBg = darkMode ? '#232c3b' : '#ffffff'
  const optionBg = darkMode ? '#1c2330' : '#ffffff'
  const optionHoverBg = darkMode ? '#2a3350' : '#f4f4f4'
  const borderColor = darkMode ? '#4a5a7a' : '#ccc'
  const textColor = darkMode ? '#eef2ff' : '#111'

  const style = document.createElement('style')
  style.textContent = `
    fieldset.fields2 .pth_select { width: auto; max-width: 18rem; }
    .pth_select { position: relative; width: auto; max-width: 18rem; }
    .custom-dropdown { width: 100%; max-width: 18rem; }
    .custom-dropdown .selected { width: 100%; border: 1px solid ${borderColor}; border-radius: 0.35rem; padding: 0.45rem 0.75rem; cursor: pointer; display: flex; align-items: center; gap: 0.55rem; min-height: 32px; line-height: 1.2; background: ${selectBg}; color: ${textColor}; font-size: 0.92rem; box-sizing: border-box; }
    .custom-dropdown .selected:hover { background: ${darkMode ? '#2c3a56' : '#f6f7f8'}; }
    .custom-dropdown .options { display: none; position: absolute; top: calc(100% + 0.2rem); left: 0; right: 0; border: 1px solid ${borderColor}; border-radius: 0.45rem; background: ${optionBg}; max-height: 220px; overflow-y: auto; z-index: 1000; box-shadow: 0 18px 40px rgba(0,0,0,0.18); }
    .custom-dropdown .option { padding: 0.45rem 0.75rem; cursor: pointer; display: flex; align-items: center; gap: 0.55rem; color: ${textColor}; font-size: 0.92rem; }
    .custom-dropdown .option:hover { background: ${optionHoverBg}; }
    .custom-dropdown .option img, .custom-dropdown .selected img { width: 20px; height: 14px; flex-shrink: 0; }
    .custom-dropdown .selected span, .custom-dropdown .option span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .custom-dropdown .selected::after { content: '▾'; margin-left: auto; font-size: 0.92rem; color: ${textColor}; }
    dt.pth_country_label { display: flex; align-items: center; }
    .pth_country_label label { color: inherit; font-size: 0.92rem; }
  `
  document.head.appendChild(style)

  // Land-Dropdown (custom dropdown mit Flaggen)
  const selectEl = document.createElement('select')
  selectEl.name = 'pth_country'
  selectEl.id   = 'pth_country'
  selectEl.style.display = 'none' // Versteckt, da custom dropdown verwendet wird
  const noneOpt = document.createElement('option')
  noneOpt.value       = ''
  noneOpt.textContent = 'none'
  selectEl.appendChild(noneOpt)
  ;(window.countries || []).forEach(c => {
    const opt = document.createElement('option')
    opt.value          = c.png
    opt.textContent    = c.title
    opt.dataset.imgsrc = '/images/flags/' + c.svg + '.svg'
    selectEl.appendChild(opt)
  })

  // Custom dropdown wrapper
  const dropdownWrapper = document.createElement('div')
  dropdownWrapper.className = 'custom-dropdown'

  // Selected display
  const selectedDiv = document.createElement('div')
  selectedDiv.className = 'selected'
  selectedDiv.innerHTML = '<span style="margin-right: 5px;">Select Country</span>'

  // Options list
  const optionsDiv = document.createElement('div')
  optionsDiv.className = 'options'
  optionsDiv.style.display = 'none'

  // Add none option and country options
  let optionsHTML = '<div class="option" data-value="" style="padding: 0.75rem 0.9rem; display: flex; align-items: center; gap: 0.65rem;"><span style="margin-right: 5px;">none</span></div>'
  ;(window.countries || []).forEach(c => {
    optionsHTML += `<div class="option" data-value="${c.png}" style="padding: 0.75rem 0.9rem; display: flex; align-items: center; gap: 0.65rem;"><img src="/images/flags/${c.svg}.svg" alt="${c.title}" style="width:20px; height:14px; margin-right:5px;"> <span>${c.title}</span></div>`
  })
  optionsDiv.innerHTML = optionsHTML

  // Toggle dropdown
  selectedDiv.addEventListener('click', () => {
    optionsDiv.style.display = optionsDiv.style.display === 'block' ? 'none' : 'block'
  })

  // Select option
  optionsDiv.addEventListener('click', (e) => {
    const option = e.target.closest('.option')
    if (!option) return
    const value = option.dataset.value
    const img = option.querySelector('img')
    const text = option.textContent.trim()
    selectEl.value = value
    selectedDiv.innerHTML = img ? `<img src="${img.src}" alt="${img.alt}" style="width:20px; height:14px; margin-right:5px;"> <span>${text}</span>` : `<span style="margin-right: 5px;">${text}</span>`
    optionsDiv.style.display = 'none'
  })

  dropdownWrapper.appendChild(selectedDiv)
  dropdownWrapper.appendChild(optionsDiv)

  // Function to set selected value
  function setSelectedValue(value) {
    selectEl.value = value
    if (value === '') {
      selectedDiv.innerHTML = '<span style="margin-right: 5px;">Select Country</span>'
    } else {
      const country = (window.countries || []).find(c => c.png === value)
      if (country) {
        selectedDiv.innerHTML = `<img src="/images/flags/${country.svg}.svg" alt="${country.title}" style="width:20px; height:14px; margin-right:5px;"> <span>${country.title}</span>`
      }
    }
  }

  const countryDt = document.createElement('dt')
  countryDt.className = 'pth_country_label'
  countryDt.innerHTML = '<label for="pth_country">Country:</label>'
  const countryDd = document.createElement('dd')
  const selectWrap = document.createElement('div')
  selectWrap.className = 'pth_select'
  selectWrap.appendChild(selectEl)
  selectWrap.appendChild(dropdownWrapper)
  countryDd.appendChild(selectWrap)
  fieldset.appendChild(countryDt)
  fieldset.appendChild(countryDd)

  // Geschlecht-Radiobuttons
  const genderDt = document.createElement('dt')
  genderDt.innerHTML = '<label>Gender:</label>'
  const genderDd = document.createElement('dd')
  genderDd.innerHTML = `
    <label><input name="pth_gender" type="radio" class="inputbox autowidth" value="m"> <span>male</span></label>
    <label><input name="pth_gender" type="radio" class="inputbox autowidth" value="f"> <span>female</span></label>
    <label><input name="pth_gender" type="radio" class="inputbox autowidth" value=""> <span>none</span></label>
  `

  fieldset.appendChild(genderDt)
  fieldset.appendChild(genderDd)
  console.log('elements appended')

  // Aktuelle Werte vom Server laden
  const loggedInEl = document.querySelector('#username_logged_in span[class^=username]')
  if (loggedInEl) {
    axios.get(window.location.origin + '/pthranking/player/gender-country?u=' + loggedInEl.textContent.trim())
      .then(res => {
        console.log('loading values', res.data)
        if (res.data.country_iso) {
          setSelectedValue(res.data.country_iso)
          console.log('set value', selectEl.value)
        }
        const radioVal = res.data.gender ?? ''
        const radio = genderDd.querySelector(`input[value="${radioVal}"]`)
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
    console.log('submitting data', data)
    // Gewähltes Gender explizit setzen (ggf. nicht übertragen wenn keines gecheckt)
    const checkedGender = genderDd.querySelector('input[name=pth_gender]:checked')
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
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initInjections)
} else {
  initInjections()
}
