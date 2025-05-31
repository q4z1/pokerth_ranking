import { first } from "lodash";

var form_done = false

document.onreadystatechange = function () {
  if (document.readyState === "complete") {
    if ($('.has-profile').length > 0) {
      $('.has-profile').each(function (i, item) {
        axios.get(window.location.origin + '/pthranking/player/gender-country?u=' + $(item).find('a[class^=username]').text())
          .then(res => {
            if (res.data.country_iso != "") {
              let country = window.countries.filter(obj => {
                return obj.png === res.data.country_iso
              })[0]
              $(item).find('a[class^=username]').parent().append(
                $('<br/>'),
                $('<img/>').attr('src', '/images/flags/' + res.data.country_iso + '.svg').css('width', '24px').css('height', '14px').css('margin-top', '2px').attr('alt', country.title).attr('title', country.title)
              )
            }
          }).catch(err => {
            console.log(err)
          })
      })
    }

    if ($('form#ucp').length > 0 && $('#pf_phpbb_location').length > 0) {
      // country flag stuff
      var styles = document.createElement('link');
      styles.rel = 'stylesheet';
      styles.type = 'text/css';
      styles.media = 'screen';
      styles.href = '/pthranking/css/countryflags.css';
      document.getElementsByTagName('head')[0].appendChild(styles);
      styles = document.createElement('link');
      styles.rel = 'stylesheet';
      styles.type = 'text/css';
      styles.media = 'screen';
      styles.href = '/pthranking/css/dd.css';
      document.getElementsByTagName('head')[0].appendChild(styles);

      let fieldset = $('form#ucp fieldset').first()
      let ctrs = $('<div/>').addClass('pth_country').append(function () {
        return [
          $('<div/>').addClass('pth_label').append(
            $('<label/>').attr('for', 'pth_ranking').text('Country Flag:')
          ),
          $('<div/>').addClass('pth_select').append(function () {
            return $('<select/>').attr('name', 'pth_country').attr('id', 'pth_country').append(function () {
              let ops = []
              ops.push(
                $('<option/>').val('').text('none')
              )
              for (let i = 0; i < window.countries.length; i++) {
                let c = window.countries[i]
                ops.push(
                  $('<option/>').val(c.png).text(c.title).attr('data-imgsrc', '/images/flags/' + c.svg + '.svg')
                )
              }
              return ops
            })
          })
        ]
      })
      let gender = $('<div/>').addClass('pth_gender').append(function () {
        return [
          $('<div/>').addClass('pth_label').append(
            $('<label/>').attr('for', 'pth_gender').text('Gender:')
          ),
          $('<div/>').append(function () {
            return [
              $('<input/>').attr('name', 'pth_gender').attr('type', 'radio').addClass('inputbox autowidth').val('m'),
              $('<span/>').text('male'),
              $('<input/>').attr('name', 'pth_gender').attr('type', 'radio').addClass('inputbox autowidth').val('f'),
              $('<span/>').text('female'),
              $('<input/>').attr('name', 'pth_gender').attr('type', 'radio').addClass('inputbox autowidth').val(''),
              $('<span/>').text('none'),
            ]
          })
        ]
      })
      $(fieldset).append(ctrs)
      $(fieldset).append(gender)

      $.getScript('/pthranking/js/dd.js', function () {
        if ($('html').hasClass('fd_dark')) {
          $('select#pth_country').gorillaDropdown(
            {
              dropdownHeight: '200px',
              backgroundColor: '#171b24',
              textFontWeight: 'normal',
              textFontColor: '#cccccc',
              padding: 6,
              borderColor: 'rgba(255,255,255,0.04)'
            }
          )
        } else {
          $('select#pth_country').gorillaDropdown(
            {
              dropdownHeight: '200px',
              backgroundColor: '#ffffff',
              textFontWeight: 'normal',
              textFontColor: '#333333',
              padding: 6,
              borderColor: '#edecec'
            }
          )
        }
      });

      axios.get(window.location.origin + '/pthranking/player/gender-country?u=' + $('#username_logged_in span[class^=username]').text())
        .then(res => {
          $('.pth_country li.dditem').removeClass('selected')
          if (res.data.country_iso != "") {
            let country = window.countries.filter(obj => {
              return obj.png === res.data.country_iso
            })[0]
            $('.pth_country input.value[value=' + res.data.country_iso + ']').parent().addClass('selected')
            $('.pth_country .current .content img.image').attr('src', '/images/flags/' + res.data.country_iso + '.svg').attr('alt', country.title).attr('title', country.title)
            $('.pth_country .current .content div.text').text($('.pth_country input.value[value=' + res.data.country_iso + ']').parent().find('div.text').text())
            $('.pth_country .current .content img.image').css('opacity', '1')
            $('.pth_gender input[value="' + res.data.gender + '"]').prop('checked', true)
          } else {
            $('.pth_country li.dditem').first().addClass('selected')
            $('.pth_country .current .content div.text').text('none')
            $('.pth_country .current .content img.image').css('opacity', '0')
            $('.pth_gender input[value=""]').prop('checked', true)
          }
        }).catch(err => {
          console.log(err)
        })
      $('#ucp').submit(function (event) {
        if (form_done) return
        event.preventDefault()
        let data = {}
        let n = $('.pth_country .content input.value').attr('name')
        if (typeof n === 'undefined' || n === false) $('.pth_country .content input.value').attr('name', 'country_iso')
        $("form#ucp :input").each(function () {
          var input = $(this)
          if ($(input).attr('name') != 'pth_gender') data[$(input).attr('name')] = $(input).val()
          else data[$(input).attr('name')] = $('.pth_gender input:checked').val()
        });
        data['username'] = $('#username_logged_in span[class^=username]').text()
        axios.post(window.location.origin + '/pthranking/player/gender-country', data)
          .then(res => {
            form_done = true
            $('#ucp input[name=submit]').click()
          }).catch(err => {
            console.log(err)
          })
      });

    }
  }
}
