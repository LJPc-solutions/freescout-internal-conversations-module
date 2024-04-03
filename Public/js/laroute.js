(function () {
    var module_routes = [
    {
        "uri": "internal-conversations\/users\/search",
        "name": "internal_conversations.users.ajax_search"
    },
    {
        "uri": "internal-conversations\/users\/add",
        "name": "internal_conversations.users.add"
    },
    {
        "uri": "internal-conversations\/users\/add_everyone",
        "name": "internal_conversations.users.add_everyone"
    },
    {
        "uri": "internal-conversations\/users\/remove",
        "name": "internal_conversations.users.remove"
    },
    {
        "uri": "internal-conversations\/users\/remove_everyone",
        "name": "internal_conversations.users.remove_everyone"
    }
];

    if (typeof(laroute) != "undefined") {
        laroute.add_routes(module_routes);
    } else {
        contole.log('laroute not initialized, can not add module routes:');
        contole.log(module_routes);
    }
})();