require('./app.js')

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
                        //console.log(res.data);
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
        // @TODO: intercept user delete (!)

    }

}

