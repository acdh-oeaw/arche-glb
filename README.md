# Repo-glb

[![Build status](https://github.com/acdh-oeaw/arche-glb/actions/workflows/deploy.yaml/badge.svg)](https://github.com/acdh-oeaw/arche-glb/actions/workflows/deploy.yaml)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-glb/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-glb?branch=master)

An ARCHE dissemination service providing downscaled on-the-fly versions of [glb](https://en.wikipedia.org/wiki/GlTF) 3D models suitable for presenting in repository GUI-embedded viewer.

To speed things up it caches provided results.

It can be queried as `{deploymentUrl}/?{parameters}`, where `{parameters}` are:

* `id={archeId}` (**required**) where the `archeId` is any identifier of an ARCHE resource. The **value should be properly URL encoded**.

