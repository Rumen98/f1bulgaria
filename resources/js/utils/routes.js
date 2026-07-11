/**
 * Ziggy може да не познава всички routes (feature флагове, частичен build) —
 * затова проверката е защитена, вместо да гърми при липсващ route.
 * route() е глобалната Ziggy функция от @routes директивата.
 */
export function hasRoute(name) {
    try {
        return route().has(name);
    } catch (e) {
        return false;
    }
}
