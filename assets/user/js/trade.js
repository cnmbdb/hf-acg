const trade = new class {
    constructor() {
    }

    //获取分类
    getCategoryList(done = null) {
        let params = {};
        if (typeof getVar === "function" && getVar("SHOP_SOURCE")) {
            params.source = getVar("SHOP_SOURCE");
        }

        util.get("/user/api/index/data" + (!util.isEmptyOrNotJson(params) ? "?" + util.objectToQueryString(params) : ""), data => {
            typeof done === "function" && done(data);
        });
    }

    //获取商品列表
    getCommodityList(opt = {}) {
        let params = {};

        if (opt.keywords) {
            params.keywords = opt.keywords;
        }

        if (opt.limit && opt.page) {
            params.limit = opt.limit;
            params.page = opt.page;
        }

        if (opt.categoryId) {
            params.categoryId = opt.categoryId;
        }

        if (opt.source) {
            params.source = opt.source;
        } else if (typeof getVar === "function" && getVar("SHOP_SOURCE")) {
            params.source = getVar("SHOP_SOURCE");
        }

        if (opt.subcategory) {
            params.subcategory = opt.subcategory;
        }

        util.get("/user/api/index/commodity" + (!util.isEmptyOrNotJson(params) ? "?" + util.objectToQueryString(params) : ""), data => {
            typeof opt.done === "function" && opt.done(data);
        });
    }

}
