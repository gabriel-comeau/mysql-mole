mysql-mole
=====

Mysql-mole is a cli-script meant to replicate the "search the entire DB for a string"
functionality of phpMyAdmin.  This is done essentially by brute force so it's important
NOT to run this against a large database.  Done against something smaller, like a typical
CMS package's DB, it works quite well and can be a useful tool when investigating how
data is stored in a package you aren't familiar with.
