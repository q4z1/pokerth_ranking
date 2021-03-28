require('./app.js')

var form_done = false

document.onreadystatechange = function () {
    if (document.readyState === "complete") {
        console.log('injection: readystate complete')
        if($('input[name=password_confirm').length > 0){
            // registration - form @action: ./ucp.php?mode=register, @id: register
            // pw edit - form @action: ./ucp.php?i=ucp_profile&mode=reg_details, @id: ucp
            console.log('password confirm here - registration or pw edit')
            if($('#register').length > 0){
                $('#register').submit(function( event ) {
                    console.log( "Handler for .submit() during reg called." );
                    // event.preventDefault();

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
                    axios.post(location.protocol + '//' + location.hostname + '/pthranking/account/change', data)
                    .then(res => {
                        form_done = true
                        $("form#ucp").submit()
                    }).catch(err => {
                        console.log(err)
                    })
                });
            }
        }
        
        if($('input[name=new_password_confirm').length > 0){
            // pw_reset - form @action: /app.php/user/reset_password, @id: reset_password
            // https://test.pokerth.net/app.php/user/reset_password?u=59&token=83qtu3l2jluf6tsp8vf20gu0oy81jupt
            // => u=59 = user_id
            // table: phpbb_users - fields: reset_token, reset_token_expiration
            console.log('new_password confirm here = pw reset')
        }

        // email bestätigung
        // https://test.pokerth.net/ucp.php?mode=activate&u=10099&k=YIUJ4TN0
        // anschl. redirect
        // besser per cron lösen?
        if(location.href.includes('mode=activate')){
            // disable redirect - check out response and then redirect
            console.log('email verification here')
            window.stop()
            // window.onbeforeunload = function(){
            //     alert('leaving page...')
            //     return 'leaving page?';
            // };
        }

    }
}