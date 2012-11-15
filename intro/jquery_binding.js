//あいう
// vim: set foldmethod=marker :

//
// easykvs.php の jQuery による利用例
//

var fetchToEasyKVS = function(url, personId, ok, ng){
    if (ok === undefined) ok = function(){};
    if (ng === undefined) ng = function(){};
    $.ajax({
        type: 'GET',
        url: url,
        cache: false,
        data: { __person_id__: personId },
        dataType: 'jsonp',
        jsonp: '__jsonp__',
        success: function(responseData){
            if (responseData.status === 'ok') {
                ok(responseData.data);
            } else {
                ng(responseData.data);
            };
        }
    });
};

var updateToEasyKVS = function(url, personId, keyValues, ok, ng){
    if (ok === undefined) ok = function(){};
    if (ng === undefined) ng = function(){};
    var merged = {
        __mode__: 'update',
        __person_id__: personId
    };
    var k;
    for (k in keyValues) { merged[k] = keyValues[k] };
    $.ajax({
        type: 'GET',
        url: url,
        cache: false,
        data: merged,
        dataType: 'jsonp',
        jsonp: '__jsonp__',
        success: function(responseData){
            if (responseData.status === 'ok') {
                ok(responseData.data);
            } else {
                ng(responseData.data);
            };
        }
    });
};

$(document).ready(function(){

    $('#update_by_jquery').mousedown(function(evt){
        var view = $('<div>')
            .css({
                position: 'absolute',
                top: evt.pageY,
                left: evt.pageX,
                width: 200,
                height: 200,
                fontSize: 12,
                lineHeight: '15px',
                backgroundColor: '#CCC'
            })
            .append($('<div>ユーザID</div>').css({ margin:10 }))
            .append(
                $('<input type="text" />')
                    .css({
                        display: 'block',
                        margin: '10px auto 0 auto',
                        width: '80%',
                        height: 15
                    })
            )
            .append($('<div>テキスト</div>').css({ margin:10 }))
            .append(
                $('<textarea />')
                    .css({
                        display: 'block',
                        margin: '10px auto 0 auto',
                        width: '80%',
                        height: 60
                    })
            )
            .append(
                $('<input type="button" value="Update" />')
                    .css({ marginTop: 10, marginLeft:10 })
                    .one('mousedown', function(evt){
                        updateToEasyKVS(
                            './api.php',
                            view.find('input').val(),
                            { txt: view.find('textarea').val() },
                            function(){
                                alert('OK!');
                                view.remove();
                            },
                            function(){
                                alert('NG...');
                                view.remove();
                            }
                        );
                        return false;
                    })
            )
            .appendTo($(document.body))
        ;
        console.log(evt);
    });

    $('#fetch_by_jquery').mousedown(function(evt){
        var view = $('<div>')
            .css({
                position: 'absolute',
                top: evt.pageY,
                left: evt.pageX,
                width: 200,
                height: 100,
                fontSize: 12,
                lineHeight: '15px',
                backgroundColor: '#CCC'
            })
            .append($('<div>ユーザID</div>').css({ margin:10 }))
            .append(
                $('<input type="text" />')
                    .css({
                        display: 'block',
                        margin: '10px auto 0 auto',
                        width: '80%',
                        height: 15
                    })
            )
            .append(
                $('<input type="button" value="Fetch" />')
                    .css({ marginTop: 10, marginLeft:10 })
                    .one('mousedown', function(evt){
                        fetchToEasyKVS(
                            './api.php',
                            view.find('input').val(),
                            function(data){
                                alert('OK!\n\n' + data.txt);
                                view.remove();
                            },
                            function(){
                                alert('NG...');
                                view.remove();
                            }
                        );
                        return false;
                    })
            )
            .appendTo($(document.body))
        ;
        console.log(evt);
    });

});
