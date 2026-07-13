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

        const source = opt.source || (typeof getVar === "function" ? getVar("SHOP_SOURCE") : "");
        if (opt.limit && opt.page) {
            params.limit = opt.limit;
            params.page = opt.page;
        } else if (source === "hstockplus" || source === "fansgurus") {
            params.limit = 100;
            params.page = 1;
        }

        if (opt.categoryId) {
            params.categoryId = opt.categoryId;
        }

        if (source) {
            params.source = source;
        }

        if (opt.subcategory) {
            params.subcategory = opt.subcategory;
        }

        if (opt.sort) {
            params.sort = opt.sort;
        }

        if (opt.inStock !== undefined) {
            params.inStock = opt.inStock ? 1 : 0;
        }

        util.get("/user/api/index/commodity" + (!util.isEmptyOrNotJson(params) ? "?" + util.objectToQueryString(params) : ""), data => {
            typeof opt.done === "function" && opt.done(data);
        });
    }

}
