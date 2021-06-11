var form_done = false

document.onreadystatechange = function () {
    if (document.readyState === "complete") {
        if($('input[name=password_confirm').length > 0){
            if($('#register').length > 0){
                $('#register').submit(function( event ) {
                    if(form_done) return
                    event.preventDefault()
                    let data = {}
                    $("form#register :input").each(function(){
                        var input = $(this)
                        data[$(input).attr('name')] = $(input).val()
                    });
                    axios.post(window.location.origin + '/pthranking/account/create', data)
                    .then(res => {
                        form_done = true
                        $('form#register input[name=submit]').click()
                    }).catch(err => {
                        console.log(err)
                    })
                });
            }else if($('#ucp').length > 0){
                $('#ucp').submit(function( event ) {
                    if(form_done) return
                    event.preventDefault()
                    let data = {}
                    $("form#ucp :input").each(function(){
                        var input = $(this)
                        data[$(input).attr('name')] = $(input).val()
                    });
                    if(!document.querySelector('#ucp #email')){
                        data['email'] = document.querySelectorAll('#ucp fieldset dl dd strong')[1].textContent
                    }
                    axios.post(window.location.origin + '/pthranking/account/change', data)
                    .then(res => {
                        form_done = true
                        $('#ucp input[name=submit]').click()
                    }).catch(err => {
                        console.log(err)
                    })
                });
            }
        }

        if($('.has-profile').length > 0){
            $('.has-profile').each(function(i, item){
                axios.get(window.location.origin + '/pthranking/player/gender-country?u=' + $(item).find('a[class^=username]').text())
                .then(res => {
                    if(res.data.country_iso != ""){
                        $(item).find('a[class^=username]').parent().append(
                            $('<br/>'),
                            $('<img/>').attr('src', '/images/flags/' + res.data.country_iso + '.svg').css('width', '24px').css('height', '14px').css('margin-top', '2px')
                        )
                    }
                }).catch(err => {
                    console.log(err)
                })
            })
        }

        if($('form#ucp').length > 0 && $('#pf_phpbb_location').length > 0){
            // user deletion
            $('#cp-menu #navigation ul').append(
                $('<li />')
                .append(
                    $('<a/>').css('cursor', 'pointer').click(() => {
                        let delmsg = '<h1 style="padding-top: 1em">Delete account</h1>'
                            + '<h2 style="text-align: center">Are you sure you want to <strong>delete</strong> your account?</h2>'
                            + '<div style="color: red; text-align: center; padding-bottom: 1em;"><i>After account deletion your username will be unavailable for re-registraion until next season!</i></div>'
                            + '<fieldset class="submit-buttons">'
                            + '<input type="button" name="confirm" value="Yes" class="button2">&nbsp;'
                            + '<input type="button" name="cancel" value="No" class="button2">'
                            + '</fieldset>'
                        phpbb.confirm(delmsg, function(del) {
                            if (!del) {
                                return;
                            }
                            let data = new FormData();
                            data.append('form_token', $('input[name=form_token]').val())
                            data.append('creation_time', $('input[name=creation_time]').val())
                            data.append('nickname', $('#username_logged_in span[class^=username]').text())
                            axios.post(window.location.origin + '/pthranking/account/delete', data)
                            .then(res => {
                                window.location.href = window.location.origin
                            }).catch(err => {
                                console.log(err)
                            })
                        }, false);
                    })
                    .append(
                        $('<span/>')
                        .css('color', '#bc8700').text('Delete account')
                    )
                )
            )
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
            let ctrs = $('<div/>').addClass('pth_country').append(function(){
                return [
                    $('<div/>').addClass('pth_label').append(
                        $('<label/>').attr('for', 'pth_ranking').text('Country Flag:')
                    ),
                    $('<div/>').addClass('pth_select').append(function(){
                        return $('<select/>').attr('name', 'pth_country').attr('id', 'pth_country').append(function(){
                            let ops = []
                            for(let i=0; i<window.countries.length; i++){
                                let c = window.countries[i]
                                ops.push(
                                    $('<option/>').val(c.png).text(c.title).attr('data-imgsrc', '/images/flags/'+c.svg+'.svg')
                                )
                            }
                            return ops
                        })
                    })
                ]
            })
            let gender = $('<div/>').addClass('pth_gender').append(function(){
                return [
                    $('<div/>').addClass('pth_label').append(
                        $('<label/>').attr('for', 'pth_gender').text('Gender:')
                    ),
                    $('<div/>').append(function(){
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

            $.getScript('/pthranking/js/dd.js', function()
            {
                if($('html').hasClass('fd_dark')){
                    $('select#pth_country').gorillaDropdown(
                        {
                            dropdownHeight	: '200px',
                            backgroundColor: '#171b24',
                            textFontWeight	: 'normal',
                            textFontColor: '#cccccc',
                            padding: 6,
                            borderColor: 'rgba(255,255,255,0.04)'
                        }
                    )
                }else{
                    $('select#pth_country').gorillaDropdown(
                        {
                            dropdownHeight	: '200px',
                            backgroundColor: '#ffffff',
                            textFontWeight	: 'normal',
                            textFontColor: '#333333',
                            padding: 6,
                            borderColor: '#edecec'
                        }
                    )
                }
            });

            axios.get(window.location.origin + '/pthranking/player/gender-country?u=' + $('#username_logged_in span[class^=username]').text())
            .then(res => {
                if(res.data.country_iso != ""){
                    $('.pth_country li.dd-item').removeClass('selected')
                    $('.pth_country input.value[value=' + res.data.country_iso + ']').parent().addClass('selected')
                    $('.pth_country .current .content img.image').attr('src', '/images/flags/' + res.data.country_iso + '.svg')
                    $('.pth_country .current .content div.text').text($('.pth_country input.value[value=' + res.data.country_iso + ']').parent().find('div.text').text())
                    $('.pth_gender input[value="'+res.data.gender+'"]').prop('checked', true)
                }
            }).catch(err => {
                console.log(err)
            })
            $('#ucp').submit(function( event ) {
                if(form_done) return
                event.preventDefault()
                let data = {}
                let n = $('.pth_country .content input.value').attr('name')
                if(typeof n === 'undefined' || n === false) $('.pth_country .content input.value').attr('name', 'country_iso')
                $("form#ucp :input").each(function(){
                    var input = $(this)
                    if($(input).attr('name') != 'pth_gender') data[$(input).attr('name')] = $(input).val()
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
        
        if($('input[name=new_password_confirm').length > 0){
            $('#reset_password').submit(function( event ) {
                if(form_done) return
                event.preventDefault()
                let data = {}
                $("form#reset_password :input").each(function(){
                    var input = $(this)
                    data[$(input).attr('name')] = $(input).val()
                })
                axios.post(window.location.origin + '/pthranking/account/reset', data)
                .then(res => {
                    // console.log(res)
                    form_done = true
                    $("form#reset_password input[name=submit]").click() // @FIXME: has to be triggered twice, or with a timeout?
                }).catch(err => {
                    console.log(err)
                })
            });
        }

        if(location.href.includes('mode=activate')){
            // disable redirect - check out response and then redirect
            console.log('email verification here')
            window.stop()
            let data = {}
            $("body :input").each(function(){
                var input = $(this)
                data[$(input).attr('name')] = $(input).val()
            })
            data.href = window.location.href
            axios.post(window.location.origin + '/pthranking/account/validate', data)
            .then(res => {
                // @TODO: redirect to board index here
                console.log(res.data)

            }).catch(err => {
                console.log(err)
            })
        }

    }
}
